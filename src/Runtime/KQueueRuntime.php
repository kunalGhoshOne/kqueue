<?php

namespace KQueue\Runtime;

use KQueue\Contracts\KQueueJobInterface;
use KQueue\Contracts\ExecutionStrategy;
use KQueue\Analysis\JobAnalyzer;
use KQueue\Swoole\SwooleStateManager;

/**
 * Core KQueue runtime powered by Swoole coroutines.
 *
 * On start(), enables SWOOLE_HOOK_ALL which transparently makes all blocking
 * PHP calls (sleep, DB queries, HTTP, file I/O) non-blocking. Every job runs
 * in its own coroutine — thousands of jobs can run concurrently on one thread
 * with zero changes to existing job code.
 */
class KQueueRuntime
{
    private array              $executionStrategies = [];
    private array              $runningJobs         = [];
    private int                $memoryLimit;
    private int                $jobsProcessed       = 0;
    private ?int               $statsTimerId        = null;
    private JobAnalyzer        $analyzer;
    private ?SwooleStateManager $stateManager;

    public function __construct(
        int $memoryLimitMB = 512,
        ?SwooleStateManager $stateManager = null
    ) {
        $this->memoryLimit  = $memoryLimitMB * 1024 * 1024;
        $this->stateManager = $stateManager;
        $this->analyzer     = new JobAnalyzer();
    }

    public function addStrategy(ExecutionStrategy $strategy): void
    {
        $this->executionStrategies[] = $strategy;
    }

    /**
     * Spawn a Swoole coroutine for the job and return immediately.
     * The job runs concurrently — this method does not block.
     */
    public function executeJob(
        KQueueJobInterface $job,
        ?callable $onSuccess = null,
        ?callable $onFailure = null
    ): void {
        $strategy  = $this->selectStrategy($job);
        $jobId     = $job->getJobId();
        $startTime = microtime(true);

        $this->runningJobs[$jobId] = [
            'started_at' => $startTime,
            'strategy'   => (new \ReflectionClass($strategy))->getShortName(),
        ];

        echo sprintf(
            "[%s] Executing %s (strategy: %s)\n",
            date('H:i:s'),
            $jobId,
            (new \ReflectionClass($strategy))->getShortName()
        );

        \Swoole\Coroutine::create(function () use ($job, $strategy, $jobId, $startTime, $onSuccess, $onFailure) {
            $this->stateManager?->prepareForJob();

            try {
                $strategy->execute($job);

                $duration = round(microtime(true) - $startTime, 2);
                echo sprintf("[%s] Job %s completed in %.2fs\n", date('H:i:s'), $jobId, $duration);

                unset($this->runningJobs[$jobId]);
                $this->jobsProcessed++;
                $onSuccess && $onSuccess();
            } catch (\Throwable $e) {
                echo sprintf("[%s] Job %s failed: %s\n", date('H:i:s'), $jobId, $e->getMessage());

                unset($this->runningJobs[$jobId]);
                $onFailure && $onFailure($e);
            } finally {
                $this->stateManager?->cleanupAfterJob();
            }
        });
    }

    /**
     * Start the runtime stats monitor.
     * Must be called from inside Swoole\Coroutine\run().
     */
    public function start(): void
    {
        echo sprintf("[%s] KQueue Runtime started (PID: %d)\n", date('Y-m-d H:i:s'), getmypid());

        $this->statsTimerId = \Swoole\Timer::tick(5000, function () {
            $memMB = round(memory_get_usage(true) / 1024 / 1024, 2);

            echo sprintf(
                "[%s] Memory: %.2fMB | Processed: %d | Running: %d\n",
                date('H:i:s'),
                $memMB,
                $this->jobsProcessed,
                count($this->runningJobs)
            );

            if (memory_get_usage(true) > $this->memoryLimit) {
                echo "[WARNING] Memory limit exceeded — consider restarting the worker\n";
            }
        });
    }

    /**
     * Stop the stats monitor. Running job coroutines finish naturally —
     * Swoole\Coroutine\run() exits once all coroutines complete.
     */
    public function stop(): void
    {
        echo sprintf(
            "[%s] Shutting down gracefully... (%d jobs still running)\n",
            date('H:i:s'),
            count($this->runningJobs)
        );

        if ($this->statsTimerId !== null) {
            \Swoole\Timer::clear($this->statsTimerId);
            $this->statsTimerId = null;
        }
    }

    /**
     * Select the best execution strategy for a job.
     * When $isolated is null, uses JobAnalyzer to auto-detect from code analysis.
     */
    private function selectStrategy(KQueueJobInterface $job): ExecutionStrategy
    {
        if ($job->isIsolated() === null) {
            $mode          = $this->analyzer->analyze($job);
            $shouldIsolate = in_array($mode, [JobAnalyzer::MODE_ISOLATED, JobAnalyzer::MODE_POOLED], true);

            echo sprintf(
                "[%s] [Analyzer] %s → %s → %s\n",
                date('H:i:s'),
                (new \ReflectionClass($job))->getShortName(),
                strtoupper($mode),
                $shouldIsolate ? 'ISOLATED process' : 'INLINE coroutine'
            );

            foreach ($this->executionStrategies as $strategy) {
                $isIsolated = $this->isIsolatedStrategyType($strategy);
                if ($shouldIsolate === $isIsolated) {
                    return $strategy;
                }
            }
        }

        foreach ($this->executionStrategies as $strategy) {
            if ($strategy->canHandle($job)) {
                return $strategy;
            }
        }

        throw new \RuntimeException('No execution strategy available for job: ' . get_class($job));
    }

    private function isIsolatedStrategyType(ExecutionStrategy $strategy): bool
    {
        return $strategy instanceof \KQueue\Execution\IsolatedExecutionStrategy
            || $strategy instanceof \KQueue\Execution\SecureIsolatedExecutionStrategy
            || $strategy instanceof \KQueue\Execution\SecureLaravelIsolatedExecutionStrategy;
    }
}
