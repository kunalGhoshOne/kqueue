<?php

namespace KQueue\Runtime;

use KQueue\Contracts\KQueueJobInterface;
use KQueue\Execution\SmartExecutionStrategySelector;
use KQueue\Analysis\JobAnalyzer;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Smart KQueue Runtime with Automatic Strategy Selection
 *
 * Uses JobAnalyzer to automatically determine the best execution strategy
 * for each job, even if the user doesn't specify one.
 *
 * Like Node.js import parser - analyzes jobs and makes smart decisions!
 */
class SmartKQueueRuntime
{
    private LoopInterface $loop;
    private SmartExecutionStrategySelector $strategySelector;
    private JobAnalyzer $analyzer;
    private array $runningJobs = [];
    private bool $running = false;
    private int $memoryLimit;
    private int $jobsProcessed = 0;

    // Conservative concurrency limits with smart learning
    private int $maxConcurrentJobs = 10;        // Current limit (starts conservative)
    private int $minConcurrentJobs = 3;         // Never go below this (safety)
    private int $maxAllowedConcurrent = 20;     // Never exceed this (hard cap)

    // System health thresholds (conservative)
    private float $maxCpuLoad = 0.7;            // Max 70% CPU load
    private float $maxMemoryPercent = 0.75;     // Max 75% memory usage

    // Performance tracking for learning
    private float $lastHealthCheck = 0;
    private int $healthCheckInterval = 30;      // Check every 30 seconds
    private array $performanceMetrics = [];

    public function __construct(
        ?LoopInterface $loop = null,
        ?SmartExecutionStrategySelector $strategySelector = null,
        ?JobAnalyzer $analyzer = null,
        int $memoryLimitMB = 512
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->analyzer = $analyzer ?? new JobAnalyzer();
        $this->strategySelector = $strategySelector ?? new SmartExecutionStrategySelector($this->analyzer);
        $this->memoryLimit = $memoryLimitMB * 1024 * 1024;
        $this->setupSignalHandlers();
    }

    /**
     * Get the strategy selector (for registering strategies)
     */
    public function getStrategySelector(): SmartExecutionStrategySelector
    {
        return $this->strategySelector;
    }

    /**
     * Get the job analyzer (for configuring thresholds)
     */
    public function getAnalyzer(): JobAnalyzer
    {
        return $this->analyzer;
    }

    /**
     * Execute a job using smart strategy selection
     */
    public function executeJob(KQueueJobInterface $job): PromiseInterface
    {
        $startTime = microtime(true);

        // CONSERVATIVE LIMIT - Prevent system overload
        if (count($this->runningJobs) >= $this->maxConcurrentJobs) {
            throw new \RuntimeException(sprintf(
                "Maximum concurrent jobs limit reached (%d). System is protecting itself from overload.",
                $this->maxConcurrentJobs
            ));
        }

        // Check system health and adjust limits if needed
        $this->monitorSystemHealth();

        // SMART SELECTION - Automatically choose best strategy!
        $strategy = $this->strategySelector->selectStrategy($job);

        $this->runningJobs[$job->getJobId()] = [
            'job' => $job,
            'started_at' => $startTime,
            'strategy' => get_class($strategy)
        ];

        echo sprintf(
            "[%s] 🧠 Smart execution: %s (strategy: %s)\n",
            date('Y-m-d H:i:s'),
            $job->getJobId(),
            $this->getShortStrategyName(get_class($strategy))
        );

        $promise = $strategy->execute($job);

        // Monitor timeout
        $timer = $this->loop->addTimer($job->getTimeout(), function() use ($job) {
            echo sprintf(
                "[%s] ⏱️ Job %s timed out after %d seconds\n",
                date('Y-m-d H:i:s'),
                $job->getJobId(),
                $job->getTimeout()
            );
            unset($this->runningJobs[$job->getJobId()]);
        });

        // Handle completion
        $promise->then(
            function() use ($job, $timer, $startTime) {
                $this->loop->cancelTimer($timer);
                $duration = microtime(true) - $startTime;

                echo sprintf(
                    "[%s] ✅ Job %s completed in %.2fs\n",
                    date('Y-m-d H:i:s'),
                    $job->getJobId(),
                    $duration
                );

                // Record execution for learning
                $this->analyzer->recordExecution(get_class($job), $duration, true);

                unset($this->runningJobs[$job->getJobId()]);
                $this->jobsProcessed++;
            },
            function($error) use ($job, $timer, $startTime) {
                $this->loop->cancelTimer($timer);
                $duration = microtime(true) - $startTime;

                echo sprintf(
                    "[%s] ❌ Job %s failed: %s\n",
                    date('Y-m-d H:i:s'),
                    $job->getJobId(),
                    $error
                );

                // Record failure for learning
                $this->analyzer->recordExecution(get_class($job), $duration, false);

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
            "[%s] 🚀 Smart KQueue Runtime started (PID: %d)\n",
            date('Y-m-d H:i:s'),
            getmypid()
        );

        // Memory and stats monitor
        $this->loop->addPeriodicTimer(10.0, function() {
            $memory = memory_get_usage(true);
            $strategyStats = $this->strategySelector->getStats();

            echo sprintf(
                "[%s] 📊 Memory: %.2f MB | Jobs: %d | Running: %d | Strategies: %s\n",
                date('Y-m-d H:i:s'),
                $memory / 1024 / 1024,
                $this->jobsProcessed,
                count($this->runningJobs),
                json_encode($strategyStats)
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
            "[%s] 🛑 Shutting down gracefully... (Running jobs: %d)\n",
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

    /**
     * Get short strategy name for display
     */
    private function getShortStrategyName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    /**
     * Get runtime statistics
     */
    public function getStats(): array
    {
        return [
            'jobs_processed' => $this->jobsProcessed,
            'running_jobs' => count($this->runningJobs),
            'memory_usage_mb' => memory_get_usage(true) / 1024 / 1024,
            'strategy_stats' => $this->strategySelector->getStats(),
            'max_concurrent' => $this->maxConcurrentJobs,
            'system_health' => $this->getSystemHealth(),
        ];
    }

    /**
     * Monitor system health and dynamically adjust concurrency limits
     *
     * CONSERVATIVE APPROACH:
     * - Reduces limit quickly when system is stressed
     * - Increases limit slowly when system is healthy
     * - Never exceeds hard cap (20)
     * - Never goes below minimum (3)
     */
    private function monitorSystemHealth(): void
    {
        $now = microtime(true);

        // Only check every 30 seconds (avoid overhead)
        if ($now - $this->lastHealthCheck < $this->healthCheckInterval) {
            return;
        }

        $this->lastHealthCheck = $now;
        $health = $this->getSystemHealth();

        // Record metrics for learning
        $this->performanceMetrics[] = [
            'timestamp' => $now,
            'concurrent_jobs' => count($this->runningJobs),
            'cpu_load' => $health['cpu_load'],
            'memory_percent' => $health['memory_percent'],
        ];

        // Keep only last 10 measurements
        if (count($this->performanceMetrics) > 10) {
            array_shift($this->performanceMetrics);
        }

        // CONSERVATIVE: Reduce limit if system is stressed
        if ($health['is_stressed']) {
            $oldLimit = $this->maxConcurrentJobs;
            $this->maxConcurrentJobs = max(
                $this->minConcurrentJobs,
                (int) ($this->maxConcurrentJobs * 0.7) // Reduce by 30%
            );

            if ($oldLimit !== $this->maxConcurrentJobs) {
                echo sprintf(
                    "[%s] ⚠️ System stressed! Reducing concurrent limit: %d → %d\n",
                    date('Y-m-d H:i:s'),
                    $oldLimit,
                    $this->maxConcurrentJobs
                );
            }
        }
        // CONSERVATIVE: Increase limit slowly if system is healthy
        elseif ($health['is_healthy'] && count($this->runningJobs) >= $this->maxConcurrentJobs * 0.8) {
            $oldLimit = $this->maxConcurrentJobs;
            $this->maxConcurrentJobs = min(
                $this->maxAllowedConcurrent,
                $this->maxConcurrentJobs + 1  // Increase by just 1 (very conservative)
            );

            if ($oldLimit !== $this->maxConcurrentJobs) {
                echo sprintf(
                    "[%s] ✅ System healthy! Increasing concurrent limit: %d → %d\n",
                    date('Y-m-d H:i:s'),
                    $oldLimit,
                    $this->maxConcurrentJobs
                );
            }
        }
    }

    /**
     * Get current system health metrics
     */
    private function getSystemHealth(): array
    {
        // Get CPU load average (last 1 minute)
        $cpuLoad = 0.0;
        if (function_exists('sys_getloadavg')) {
            $loadAvg = sys_getloadavg();
            $cpuCores = $this->getCpuCores();
            $cpuLoad = $loadAvg[0] / $cpuCores; // Normalize by CPU cores
        }

        // Get memory usage percentage
        $memoryUsed = memory_get_usage(true);
        $memoryPercent = $memoryUsed / $this->memoryLimit;

        // Determine if system is stressed or healthy
        $isStressed = $cpuLoad > $this->maxCpuLoad || $memoryPercent > $this->maxMemoryPercent;
        $isHealthy = $cpuLoad < ($this->maxCpuLoad * 0.5) && $memoryPercent < ($this->maxMemoryPercent * 0.5);

        return [
            'cpu_load' => round($cpuLoad, 2),
            'memory_percent' => round($memoryPercent, 2),
            'memory_mb' => round($memoryUsed / 1024 / 1024, 2),
            'is_stressed' => $isStressed,
            'is_healthy' => $isHealthy,
        ];
    }

    /**
     * Get number of CPU cores
     */
    private function getCpuCores(): int
    {
        static $cores = null;

        if ($cores !== null) {
            return $cores;
        }

        // Try different methods to detect CPU cores
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            $cores = count($matches[0]);
        }

        // Fallback
        if (!$cores) {
            $cores = 2; // Conservative fallback
        }

        return $cores;
    }
}
