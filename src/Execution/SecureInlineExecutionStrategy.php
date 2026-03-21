<?php

namespace KQueue\Execution;

use KQueue\Contracts\ExecutionStrategy;
use KQueue\Contracts\KQueueJobInterface;

/**
 * Inline execution with server-side memory limit enforcement.
 *
 * Runs in the current Swoole coroutine. SWOOLE_HOOK_ALL makes all blocking
 * I/O non-blocking automatically. Adds a memory cap so a single job cannot
 * exhaust the process.
 *
 * Best for: I/O-bound jobs in production (emails, HTTP, DB queries, cache)
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
        return $job->isIsolated() === false;
    }

    public function execute(KQueueJobInterface $job): void
    {
        $jobMemoryLimit  = min($job->getMaxMemory(), $this->maxMemory);
        $oldMemoryLimit  = ini_get('memory_limit');

        ini_set('memory_limit', $jobMemoryLimit . 'M');

        try {
            $job->handle();
        } catch (\Throwable $e) {
            error_log(sprintf('[KQueue] Inline job failed: %s', $e->getMessage()));
            throw $e;
        } finally {
            ini_set('memory_limit', $oldMemoryLimit);
        }
    }
}
