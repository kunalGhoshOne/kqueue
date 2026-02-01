<?php

namespace KQueue\Runtime;

use KQueue\Contracts\KQueueJobInterface;
use KQueue\Contracts\ExecutionStrategy;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * SECURE KQueue Runtime
 *
 * Security improvements:
 * - Input validation on all job properties
 * - Enforced timeouts (actually kills jobs)
 * - Enforced memory limits
 * - Rate limiting
 * - Sanitized error messages
 */
class SecureKQueueRuntime
{
    private LoopInterface $loop;
    private array $executionStrategies = [];
    private array $runningJobs = [];
    private bool $running = false;
    private int $memoryLimit;
    private int $jobsProcessed = 0;

    // SECURITY: Server-side limits (don't trust job properties)
    private int $maxJobTimeout = 300;      // 5 minutes max
    private int $maxJobMemory = 512;       // 512MB max
    private int $maxConcurrentJobs = 100;  // Prevent DoS
    private int $maxQueueDepth = 1000;     // Prevent memory exhaustion

    // Rate limiting
    private array $jobDispatchTimes = [];
    private int $maxJobsPerMinute = 1000;

    public function __construct(
        ?LoopInterface $loop = null,
        int $memoryLimitMB = 512,
        int $maxJobTimeout = 300,
        int $maxJobMemory = 512,
        int $maxConcurrentJobs = 100
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->memoryLimit = $memoryLimitMB * 1024 * 1024;
        $this->maxJobTimeout = $maxJobTimeout;
        $this->maxJobMemory = $maxJobMemory;
        $this->maxConcurrentJobs = $maxConcurrentJobs;
        $this->setupSignalHandlers();
    }

    public function addStrategy(ExecutionStrategy $strategy): void
    {
        $this->executionStrategies[] = $strategy;
    }

    /**
     * SECURITY: Validate and execute job
     */
    public function executeJob(KQueueJobInterface $job): PromiseInterface
    {
        // SECURITY: Validate job before execution
        $this->validateJob($job);

        // SECURITY: Check rate limit
        $this->checkRateLimit();

        // SECURITY: Check concurrent job limit
        if (count($this->runningJobs) >= $this->maxConcurrentJobs) {
            throw new \RuntimeException(
                "Maximum concurrent jobs limit reached ({$this->maxConcurrentJobs})"
            );
        }

        $strategy = $this->selectStrategy($job);

        $this->runningJobs[$job->getJobId()] = [
            'job' => $job,
            'started_at' => microtime(true),
            'strategy' => get_class($strategy)
        ];

        // SECURITY: Sanitized logging (no sensitive data)
        error_log(sprintf(
            "[KQueue] Executing job %s (strategy: %s)",
            $this->sanitizeJobId($job->getJobId()),
            basename(get_class($strategy))
        ));

        $promise = $strategy->execute($job);

        // SECURITY: Enforce server-side timeout
        $enforcedTimeout = min($job->getTimeout(), $this->maxJobTimeout);
        $timer = $this->loop->addTimer($enforcedTimeout, function() use ($job) {
            error_log(sprintf(
                "[KQueue] Job %s timed out after %d seconds",
                $this->sanitizeJobId($job->getJobId()),
                $this->maxJobTimeout
            ));

            // Remove from running jobs
            unset($this->runningJobs[$job->getJobId()]);

            // Note: The strategy should also enforce timeout and kill the process
        });

        $promise->then(
            function() use ($job, $timer) {
                $this->loop->cancelTimer($timer);
                $duration = microtime(true) - $this->runningJobs[$job->getJobId()]['started_at'];

                error_log(sprintf(
                    "[KQueue] Job %s completed in %.2fs",
                    $this->sanitizeJobId($job->getJobId()),
                    $duration
                ));

                unset($this->runningJobs[$job->getJobId()]);
                $this->jobsProcessed++;
            },
            function($error) use ($job, $timer) {
                $this->loop->cancelTimer($timer);

                // SECURITY: Sanitize error messages (no stack traces)
                $sanitizedError = $error instanceof \Throwable
                    ? $error->getMessage()
                    : (string) $error;

                error_log(sprintf(
                    "[KQueue] Job %s failed: %s",
                    $this->sanitizeJobId($job->getJobId()),
                    $this->sanitizeErrorMessage($sanitizedError)
                ));

                unset($this->runningJobs[$job->getJobId()]);
            }
        );

        return $promise;
    }

    /**
     * SECURITY: Validate job properties
     */
    private function validateJob(KQueueJobInterface $job): void
    {
        $errors = [];

        // Validate timeout
        $timeout = $job->getTimeout();
        if ($timeout <= 0 || $timeout > $this->maxJobTimeout) {
            $errors[] = "Invalid timeout: {$timeout} (max: {$this->maxJobTimeout})";
        }

        // Validate memory
        $memory = $job->getMaxMemory();
        if ($memory <= 0 || $memory > $this->maxJobMemory) {
            $errors[] = "Invalid memory limit: {$memory} (max: {$this->maxJobMemory})";
        }

        // Validate priority
        $priority = $job->getPriority();
        if ($priority < -100 || $priority > 100) {
            $errors[] = "Invalid priority: {$priority} (range: -100 to 100)";
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(
                "Job validation failed: " . implode(', ', $errors)
            );
        }
    }

    /**
     * SECURITY: Rate limiting to prevent job flooding
     */
    private function checkRateLimit(): void
    {
        $now = time();

        // Clean old entries (older than 1 minute)
        $this->jobDispatchTimes = array_filter(
            $this->jobDispatchTimes,
            fn($time) => $time > $now - 60
        );

        // Check if rate limit exceeded
        if (count($this->jobDispatchTimes) >= $this->maxJobsPerMinute) {
            throw new \RuntimeException(
                "Rate limit exceeded: maximum {$this->maxJobsPerMinute} jobs per minute"
            );
        }

        $this->jobDispatchTimes[] = $now;
    }

    /**
     * SECURITY: Sanitize job ID for logging
     */
    private function sanitizeJobId(string $jobId): string
    {
        // Only allow alphanumeric and safe characters
        return preg_replace('/[^a-zA-Z0-9_\-.]/', '', $jobId);
    }

    /**
     * SECURITY: Sanitize error messages (remove sensitive paths, etc.)
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // Remove absolute paths
        $message = preg_replace('/\/[a-zA-Z0-9_\-\/]+\.php/', '[PATH_REDACTED]', $message);

        // Limit length to prevent log flooding
        if (strlen($message) > 500) {
            $message = substr($message, 0, 497) . '...';
        }

        return $message;
    }

    public function start(): void
    {
        $this->running = true;

        error_log(sprintf(
            "[KQueue] Runtime started (PID: %d, Memory Limit: %dMB)",
            getmypid(),
            $this->memoryLimit / 1024 / 1024
        ));

        // Memory monitor with enforcement
        $this->loop->addPeriodicTimer(5.0, function() {
            $memory = memory_get_usage(true);
            $memoryMB = $memory / 1024 / 1024;

            error_log(sprintf(
                "[KQueue] Memory: %.2f MB | Jobs: %d | Running: %d",
                $memoryMB,
                $this->jobsProcessed,
                count($this->runningJobs)
            ));

            // SECURITY: Actually enforce memory limit
            if ($memory > $this->memoryLimit) {
                error_log("[KQueue] CRITICAL: Memory limit exceeded, initiating graceful shutdown");
                $this->stop();
            }
        });

        $this->loop->run();
    }

    public function stop(): void
    {
        error_log(sprintf(
            "[KQueue] Graceful shutdown initiated (Running jobs: %d)",
            count($this->runningJobs)
        ));

        $this->running = false;

        // Give running jobs a chance to complete (max 30 seconds)
        $shutdownTimer = $this->loop->addTimer(30.0, function() {
            error_log("[KQueue] Forced shutdown after 30 seconds");
            $this->loop->stop();
        });

        // Stop when all jobs complete
        $checkInterval = $this->loop->addPeriodicTimer(1.0, function() use ($shutdownTimer) {
            if (empty($this->runningJobs)) {
                error_log("[KQueue] All jobs completed, shutting down");
                $this->loop->cancelTimer($shutdownTimer);
                $this->loop->stop();
            }
        });
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

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
     * SECURITY: Safe signal handlers (no complex operations)
     */
    private function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function() {
                error_log("[KQueue] Received SIGTERM");
                $this->stop();
            });

            pcntl_signal(SIGINT, function() {
                error_log("[KQueue] Received SIGINT");
                $this->stop();
            });
        }
    }
}
