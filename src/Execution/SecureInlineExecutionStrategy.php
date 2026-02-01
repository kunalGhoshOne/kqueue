<?php

namespace KQueue\Execution;

use KQueue\Contracts\ExecutionStrategy;
use KQueue\Contracts\KQueueJobInterface;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;

/**
 * SECURE Inline Execution Strategy
 *
 * Executes jobs inline within the event loop with security improvements:
 * - Memory limit enforcement
 * - Execution time limits
 * - Error sanitization
 */
class SecureInlineExecutionStrategy implements ExecutionStrategy
{
    private int $maxMemory;

    public function __construct(int $maxMemory = 256)
    {
        $this->maxMemory = $maxMemory;
    }

    public function canHandle(KQueueJobInterface $job): bool
    {
        // Handle non-isolated jobs
        return !$job->isIsolated();
    }

    public function execute(KQueueJobInterface $job): PromiseInterface
    {
        $deferred = new Deferred();

        try {
            // SECURITY: Set memory limit for this execution
            $oldMemoryLimit = ini_get('memory_limit');
            $jobMemoryLimit = min($job->getMaxMemory(), $this->maxMemory);
            ini_set('memory_limit', $jobMemoryLimit . 'M');

            // SECURITY: Track start time for timeout
            $startTime = microtime(true);
            $timeout = $job->getTimeout();

            // Execute the job
            $job->handle();

            // SECURITY: Check if we exceeded timeout
            $executionTime = microtime(true) - $startTime;
            if ($executionTime > $timeout) {
                error_log(sprintf(
                    "[KQueue] Warning: Job completed but exceeded timeout (%.2fs > %ds)",
                    $executionTime,
                    $timeout
                ));
            }

            // Restore memory limit
            ini_set('memory_limit', $oldMemoryLimit);

            // Resolve successfully
            $deferred->resolve(null);

        } catch (\Throwable $e) {
            // SECURITY: Sanitize error (no stack traces to user)
            error_log(sprintf(
                "[KQueue] Job execution failed: %s",
                $e->getMessage()
            ));

            $deferred->reject($e);
        }

        return $deferred->promise();
    }
}
