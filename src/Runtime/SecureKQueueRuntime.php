<?php

namespace KQueue\Runtime;

use KQueue\Contracts\KQueueJobInterface;
use KQueue\Contracts\ExecutionStrategy;
use KQueue\Swoole\SwooleStateManager;

/**
 * Hardened KQueue runtime for production deployments.
 *
 * Adds on top of the base Swoole coroutine runtime:
 *  - Server-side job validation (timeout, memory, priority ranges)
 *  - Rate limiting (max jobs per minute)
 *  - Max concurrent job cap to prevent system overload
 *  - Sanitized error logging (no stack traces or paths in output)
 *  - Graceful shutdown with 30-second hard deadline
 */
class SecureKQueueRuntime
{
    private array              $executionStrategies = [];
    private array              $runningJobs         = [];
    private int                $memoryLimit;
    private int                $jobsProcessed       = 0;
    private ?int               $statsTimerId        = null;
    private ?SwooleStateManager $stateManager;

    // Server-side limits — jobs cannot exceed these
    private int $maxJobTimeout    = 300;
    private int $maxJobMemory     = 512;
    private int $maxConcurrentJobs = 100;

    // Rate limiting
    private array $jobDispatchTimes = [];
    private int   $maxJobsPerMinute = 1000;

    public function __construct(
        int $memoryLimitMB    = 512,
        int $maxJobTimeout    = 300,
        int $maxJobMemory     = 512,
        int $maxConcurrentJobs = 100,
        ?SwooleStateManager $stateManager = null
    ) {
        $this->memoryLimit      = $memoryLimitMB * 1024 * 1024;
        $this->maxJobTimeout    = $maxJobTimeout;
        $this->maxJobMemory     = $maxJobMemory;
        $this->maxConcurrentJobs = $maxConcurrentJobs;
        $this->stateManager     = $stateManager;
    }

    public function addStrategy(ExecutionStrategy $strategy): void
    {
        $this->executionStrategies[] = $strategy;
    }

    public function executeJob(
        KQueueJobInterface $job,
        ?callable $onSuccess = null,
        ?callable $onFailure = null
    ): void {
        // Security checks before spawning
        $this->validateJob($job);
        $this->checkRateLimit();

        if (count($this->runningJobs) >= $this->maxConcurrentJobs) {
            throw new \RuntimeException(
                "Maximum concurrent jobs limit reached ({$this->maxConcurrentJobs})"
            );
        }

        $strategy  = $this->selectStrategy($job);
        $jobId     = $this->sanitizeJobId($job->getJobId());
        $startTime = microtime(true);

        $this->runningJobs[$jobId] = [
            'started_at' => $startTime,
            'strategy'   => basename(get_class($strategy)),
        ];

        error_log(sprintf(
            '[KQueue] Executing job %s (strategy: %s)',
            $jobId,
            basename(get_class($strategy))
        ));

        \Swoole\Coroutine::create(function () use ($job, $strategy, $jobId, $startTime, $onSuccess, $onFailure) {
            $this->stateManager?->prepareForJob();

            try {
                $strategy->execute($job);

                $duration = round(microtime(true) - $startTime, 2);
                error_log(sprintf('[KQueue] Job %s completed in %.2fs', $jobId, $duration));

                unset($this->runningJobs[$jobId]);
                $this->jobsProcessed++;
                $onSuccess && $onSuccess();
            } catch (\Throwable $e) {
                // Sanitize error — no stack traces in logs
                error_log(sprintf(
                    '[KQueue] Job %s failed: %s',
                    $jobId,
                    $this->sanitizeErrorMessage($e->getMessage())
                ));

                unset($this->runningJobs[$jobId]);
                $onFailure && $onFailure($e);
            } finally {
                $this->stateManager?->cleanupAfterJob();
            }
        });
    }

    public function start(): void
    {
        error_log(sprintf(
            '[KQueue] Secure runtime started (PID: %d, Memory limit: %dMB)',
            getmypid(),
            $this->memoryLimit / 1024 / 1024
        ));

        $this->statsTimerId = \Swoole\Timer::tick(5000, function () {
            $memMB = memory_get_usage(true) / 1024 / 1024;

            error_log(sprintf(
                '[KQueue] Memory: %.2fMB | Processed: %d | Running: %d',
                $memMB,
                $this->jobsProcessed,
                count($this->runningJobs)
            ));

            // Hard memory enforcement — stop accepting new jobs if over limit
            if (memory_get_usage(true) > $this->memoryLimit) {
                error_log('[KQueue] CRITICAL: Memory limit exceeded, initiating graceful shutdown');
                $this->stop();
            }
        });
    }

    public function stop(): void
    {
        error_log(sprintf(
            '[KQueue] Graceful shutdown initiated (Running jobs: %d)',
            count($this->runningJobs)
        ));

        if ($this->statsTimerId !== null) {
            \Swoole\Timer::clear($this->statsTimerId);
            $this->statsTimerId = null;
        }

        // If jobs are still running, force-exit after 30 seconds
        if (!empty($this->runningJobs)) {
            \Swoole\Timer::after(30000, function () {
                if (!empty($this->runningJobs)) {
                    error_log('[KQueue] Forced shutdown after 30-second grace period');
                    exit(1);
                }
            });
        }
    }

    private function validateJob(KQueueJobInterface $job): void
    {
        $errors = [];

        $timeout = $job->getTimeout();
        if ($timeout <= 0 || $timeout > $this->maxJobTimeout) {
            $errors[] = "Invalid timeout: {$timeout} (max: {$this->maxJobTimeout})";
        }

        $memory = $job->getMaxMemory();
        if ($memory <= 0 || $memory > $this->maxJobMemory) {
            $errors[] = "Invalid memory: {$memory} (max: {$this->maxJobMemory})";
        }

        $priority = $job->getPriority();
        if ($priority < -100 || $priority > 100) {
            $errors[] = "Invalid priority: {$priority} (range: -100 to 100)";
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException('Job validation failed: ' . implode(', ', $errors));
        }
    }

    private function checkRateLimit(): void
    {
        $now = time();

        $this->jobDispatchTimes = array_filter(
            $this->jobDispatchTimes,
            fn($t) => $t > $now - 60
        );

        if (count($this->jobDispatchTimes) >= $this->maxJobsPerMinute) {
            throw new \RuntimeException(
                "Rate limit exceeded: max {$this->maxJobsPerMinute} jobs per minute"
            );
        }

        $this->jobDispatchTimes[] = $now;
    }

    private function selectStrategy(KQueueJobInterface $job): ExecutionStrategy
    {
        foreach ($this->executionStrategies as $strategy) {
            if ($strategy->canHandle($job)) {
                return $strategy;
            }
        }

        throw new \RuntimeException('No execution strategy available for job: ' . get_class($job));
    }

    private function sanitizeJobId(string $jobId): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-.]/', '', $jobId);
    }

    private function sanitizeErrorMessage(string $message): string
    {
        // Remove absolute file paths
        $message = preg_replace('/\/[a-zA-Z0-9_\-\/]+\.php/', '[PATH_REDACTED]', $message);

        // Cap length to prevent log flooding
        return strlen($message) > 500 ? substr($message, 0, 497) . '...' : $message;
    }
}
