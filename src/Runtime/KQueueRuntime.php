<?php

namespace KQueue\Runtime;

use KQueue\Contracts\KQueueJobInterface;
use KQueue\Contracts\ExecutionStrategy;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

class KQueueRuntime
{
    private LoopInterface $loop;
    private array $executionStrategies = [];
    private array $runningJobs = [];
    private bool $running = false;
    private int $memoryLimit;
    private int $jobsProcessed = 0;

    public function __construct(?LoopInterface $loop = null, int $memoryLimitMB = 512)
    {
        $this->loop = $loop ?? Loop::get();
        $this->memoryLimit = $memoryLimitMB * 1024 * 1024; // Convert to bytes
        $this->setupSignalHandlers();
    }

    /**
     * Register an execution strategy
     */
    public function addStrategy(ExecutionStrategy $strategy): void
    {
        $this->executionStrategies[] = $strategy;
    }

    /**
     * Execute a job using the appropriate strategy
     */
    public function executeJob(KQueueJobInterface $job): PromiseInterface
    {
        $strategy = $this->selectStrategy($job);

        $this->runningJobs[$job->getJobId()] = [
            'job' => $job,
            'started_at' => microtime(true),
            'strategy' => get_class($strategy)
        ];

        echo sprintf(
            "[%s] Executing job %s (strategy: %s)\n",
            date('Y-m-d H:i:s'),
            $job->getJobId(),
            get_class($strategy)
        );

        $promise = $strategy->execute($job);

        // Monitor timeout
        $timer = $this->loop->addTimer($job->getTimeout(), function() use ($job) {
            echo sprintf(
                "[%s] Job %s timed out after %d seconds\n",
                date('Y-m-d H:i:s'),
                $job->getJobId(),
                $job->getTimeout()
            );
            unset($this->runningJobs[$job->getJobId()]);
        });

        // Handle completion
        $promise->then(
            function() use ($job, $timer) {
                $this->loop->cancelTimer($timer);
                $duration = microtime(true) - $this->runningJobs[$job->getJobId()]['started_at'];
                echo sprintf(
                    "[%s] Job %s completed in %.2fs\n",
                    date('Y-m-d H:i:s'),
                    $job->getJobId(),
                    $duration
                );
                unset($this->runningJobs[$job->getJobId()]);
                $this->jobsProcessed++;
            },
            function($error) use ($job, $timer) {
                $this->loop->cancelTimer($timer);
                echo sprintf(
                    "[%s] Job %s failed: %s\n",
                    date('Y-m-d H:i:s'),
                    $job->getJobId(),
                    $error
                );
                unset($this->runningJobs[$job->getJobId()]);
            }
        );

        return $promise;
    }

    /**
     * Start the runtime
     */
    public function start(): void
    {
        $this->running = true;

        echo sprintf(
            "[%s] KQueue Runtime started (PID: %d)\n",
            date('Y-m-d H:i:s'),
            getmypid()
        );

        // Memory monitor
        $this->loop->addPeriodicTimer(5.0, function() {
            $memory = memory_get_usage(true);
            echo sprintf(
                "[%s] Memory: %.2f MB | Jobs processed: %d | Running: %d\n",
                date('Y-m-d H:i:s'),
                $memory / 1024 / 1024,
                $this->jobsProcessed,
                count($this->runningJobs)
            );

            if ($memory > $this->memoryLimit) {
                echo "[WARNING] Memory limit exceeded, consider restart\n";
            }
        });

        $this->loop->run();
    }

    /**
     * Stop the runtime gracefully
     */
    public function stop(): void
    {
        echo sprintf(
            "[%s] Shutting down gracefully... (Running jobs: %d)\n",
            date('Y-m-d H:i:s'),
            count($this->runningJobs)
        );

        $this->running = false;
        $this->loop->stop();
    }

    /**
     * Get the event loop
     */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /**
     * Select the best execution strategy for a job
     */
    private function selectStrategy(KQueueJobInterface $job): ExecutionStrategy
    {
        foreach ($this->executionStrategies as $strategy) {
            if ($strategy->canHandle($job)) {
                return $strategy;
            }
        }

        throw new \RuntimeException('No execution strategy available for job');
    }

    /**
     * Setup signal handlers for graceful shutdown
     */
    private function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function() {
                $this->stop();
            });

            pcntl_signal(SIGINT, function() {
                $this->stop();
            });
        }
    }
}
