<?php

namespace KQueue\Contracts;

/**
 * Execution strategy for KQueue jobs.
 *
 * All strategies run inside a Swoole coroutine context where
 * SWOOLE_HOOK_ALL is active. This means sleep(), DB queries,
 * HTTP calls, and file I/O are automatically non-blocking —
 * no special code required in job implementations.
 */
interface ExecutionStrategy
{
    /**
     * Execute the job.
     *
     * Called from within a Swoole coroutine. All blocking I/O inside
     * the job is automatically yielded to the scheduler, allowing other
     * jobs to run concurrently on the same thread.
     *
     * @throws \Throwable on failure
     */
    public function execute(KQueueJobInterface $job): void;

    /**
     * Check if this strategy can handle the given job.
     */
    public function canHandle(KQueueJobInterface $job): bool;
}
