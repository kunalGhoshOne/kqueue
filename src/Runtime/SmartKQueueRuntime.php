<?php

namespace KQueue\Runtime;

use KQueue\Contracts\KQueueJobInterface;
use KQueue\Execution\SmartExecutionStrategySelector;
use KQueue\Analysis\JobAnalyzer;
use KQueue\Swoole\SwooleStateManager;

/**
 * Smart KQueue runtime with automatic strategy selection and adaptive concurrency.
 *
 * Uses JobAnalyzer to automatically determine the optimal execution strategy
 * for each job based on code analysis, historical data, and name patterns.
 * Dynamically adjusts the concurrency limit based on live CPU and memory metrics.
 *
 * Runs on Swoole coroutines — SWOOLE_HOOK_ALL makes all I/O non-blocking.
 */
class SmartKQueueRuntime
{
    private SmartExecutionStrategySelector $strategySelector;
    private JobAnalyzer                    $analyzer;
    private array                          $runningJobs    = [];
    private int                            $jobsProcessed  = 0;
    private int                            $memoryLimit;
    private ?int                           $statsTimerId   = null;
    private ?SwooleStateManager            $stateManager;

    // Adaptive concurrency — starts conservative, learns from system health
    private int   $maxConcurrentJobs  = 10;
    private int   $minConcurrentJobs  = 3;
    private int   $maxAllowedConcurrent = 20;

    // Health thresholds
    private float $maxCpuLoad        = 0.7;
    private float $maxMemoryPercent  = 0.75;

    private float $lastHealthCheck      = 0;
    private int   $healthCheckInterval  = 30;
    private array $performanceMetrics   = [];

    public function __construct(
        ?SmartExecutionStrategySelector $strategySelector = null,
        ?JobAnalyzer $analyzer = null,
        int $memoryLimitMB = 512,
        ?SwooleStateManager $stateManager = null
    ) {
        $this->analyzer        = $analyzer ?? new JobAnalyzer();
        $this->strategySelector = $strategySelector ?? new SmartExecutionStrategySelector($this->analyzer);
        $this->memoryLimit     = $memoryLimitMB * 1024 * 1024;
        $this->stateManager    = $stateManager;
    }

    public function getStrategySelector(): SmartExecutionStrategySelector
    {
        return $this->strategySelector;
    }

    public function getAnalyzer(): JobAnalyzer
    {
        return $this->analyzer;
    }

    public function executeJob(
        KQueueJobInterface $job,
        ?callable $onSuccess = null,
        ?callable $onFailure = null
    ): void {
        if (count($this->runningJobs) >= $this->maxConcurrentJobs) {
            throw new \RuntimeException(sprintf(
                'Max concurrent jobs limit reached (%d). System protecting itself from overload.',
                $this->maxConcurrentJobs
            ));
        }

        $this->monitorSystemHealth();

        $strategy  = $this->strategySelector->selectStrategy($job);
        $jobId     = $job->getJobId();
        $startTime = microtime(true);

        $this->runningJobs[$jobId] = [
            'started_at' => $startTime,
            'strategy'   => (new \ReflectionClass($strategy))->getShortName(),
        ];

        echo sprintf(
            "[%s] Smart execution: %s (strategy: %s)\n",
            date('Y-m-d H:i:s'),
            $jobId,
            (new \ReflectionClass($strategy))->getShortName()
        );

        \Swoole\Coroutine::create(function () use ($job, $strategy, $jobId, $startTime, $onSuccess, $onFailure) {
            $this->stateManager?->prepareForJob();

            try {
                $strategy->execute($job);

                $duration = round(microtime(true) - $startTime, 2);
                echo sprintf("[%s] Job %s completed in %.2fs\n", date('Y-m-d H:i:s'), $jobId, $duration);

                $this->analyzer->recordExecution(get_class($job), $duration, true);
                unset($this->runningJobs[$jobId]);
                $this->jobsProcessed++;
                $onSuccess && $onSuccess();
            } catch (\Throwable $e) {
                $duration = round(microtime(true) - $startTime, 2);
                echo sprintf("[%s] Job %s failed: %s\n", date('Y-m-d H:i:s'), $jobId, $e->getMessage());

                $this->analyzer->recordExecution(get_class($job), $duration, false);
                unset($this->runningJobs[$jobId]);
                $onFailure && $onFailure($e);
            } finally {
                $this->stateManager?->cleanupAfterJob();
            }
        });
    }

    public function start(): void
    {
        echo sprintf("[%s] Smart KQueue Runtime started (PID: %d)\n", date('Y-m-d H:i:s'), getmypid());

        $this->statsTimerId = \Swoole\Timer::tick(10000, function () {
            $memMB         = round(memory_get_usage(true) / 1024 / 1024, 2);
            $strategyStats = $this->strategySelector->getStats();

            echo sprintf(
                "[%s] Memory: %.2fMB | Processed: %d | Running: %d | Concurrency limit: %d | Strategies: %s\n",
                date('H:i:s'),
                $memMB,
                $this->jobsProcessed,
                count($this->runningJobs),
                $this->maxConcurrentJobs,
                json_encode($strategyStats)
            );

            if (memory_get_usage(true) > $this->memoryLimit) {
                echo "[WARNING] Memory limit exceeded — consider restarting\n";
            }
        });
    }

    public function stop(): void
    {
        echo sprintf(
            "[%s] Shutting down... (%d jobs still running)\n",
            date('H:i:s'),
            count($this->runningJobs)
        );

        if ($this->statsTimerId !== null) {
            \Swoole\Timer::clear($this->statsTimerId);
            $this->statsTimerId = null;
        }
    }

    public function getStats(): array
    {
        return [
            'jobs_processed'  => $this->jobsProcessed,
            'running_jobs'    => count($this->runningJobs),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'strategy_stats'  => $this->strategySelector->getStats(),
            'max_concurrent'  => $this->maxConcurrentJobs,
            'system_health'   => $this->getSystemHealth(),
        ];
    }

    /**
     * Dynamically adjust the concurrency limit based on system health.
     * Reduces quickly under stress, increases slowly when healthy.
     */
    private function monitorSystemHealth(): void
    {
        $now = microtime(true);

        if ($now - $this->lastHealthCheck < $this->healthCheckInterval) {
            return;
        }

        $this->lastHealthCheck = $now;
        $health = $this->getSystemHealth();

        $this->performanceMetrics[] = [
            'timestamp'      => $now,
            'concurrent'     => count($this->runningJobs),
            'cpu_load'       => $health['cpu_load'],
            'memory_percent' => $health['memory_percent'],
        ];

        if (count($this->performanceMetrics) > 10) {
            array_shift($this->performanceMetrics);
        }

        if ($health['is_stressed']) {
            $old = $this->maxConcurrentJobs;
            $this->maxConcurrentJobs = max(
                $this->minConcurrentJobs,
                (int) ($this->maxConcurrentJobs * 0.7) // Reduce by 30%
            );

            if ($old !== $this->maxConcurrentJobs) {
                echo sprintf(
                    "[%s] System stressed — concurrency limit: %d -> %d\n",
                    date('H:i:s'), $old, $this->maxConcurrentJobs
                );
            }
        } elseif ($health['is_healthy'] && count($this->runningJobs) >= $this->maxConcurrentJobs * 0.8) {
            $old = $this->maxConcurrentJobs;
            $this->maxConcurrentJobs = min(
                $this->maxAllowedConcurrent,
                $this->maxConcurrentJobs + 1 // Increase slowly — 1 at a time
            );

            if ($old !== $this->maxConcurrentJobs) {
                echo sprintf(
                    "[%s] System healthy — concurrency limit: %d -> %d\n",
                    date('H:i:s'), $old, $this->maxConcurrentJobs
                );
            }
        }
    }

    private function getSystemHealth(): array
    {
        $cpuLoad = 0.0;
        if (function_exists('sys_getloadavg')) {
            $loadAvg = sys_getloadavg();
            $cpuLoad = $loadAvg[0] / $this->getCpuCores();
        }

        $memoryUsed    = memory_get_usage(true);
        $memoryPercent = $memoryUsed / $this->memoryLimit;

        return [
            'cpu_load'       => round($cpuLoad, 2),
            'memory_percent' => round($memoryPercent, 2),
            'memory_mb'      => round($memoryUsed / 1024 / 1024, 2),
            'is_stressed'    => $cpuLoad > $this->maxCpuLoad || $memoryPercent > $this->maxMemoryPercent,
            'is_healthy'     => $cpuLoad < ($this->maxCpuLoad * 0.5) && $memoryPercent < ($this->maxMemoryPercent * 0.5),
        ];
    }

    private function getCpuCores(): int
    {
        static $cores = null;

        if ($cores !== null) {
            return $cores;
        }

        if (is_file('/proc/cpuinfo')) {
            preg_match_all('/^processor/m', file_get_contents('/proc/cpuinfo'), $matches);
            $cores = count($matches[0]);
        }

        return $cores ?: 2;
    }
}
