<?php

namespace KQueue\Execution;

use KQueue\Contracts\ExecutionStrategy;
use KQueue\Contracts\KQueueJobInterface;

/**
 * Runs the job in the current Swoole coroutine.
 *
 * Because SWOOLE_HOOK_ALL is enabled at worker startup, every blocking call
 * inside the job — sleep(), DB queries, HTTP requests, file I/O, Redis — is
 * automatically non-blocking. Thousands of jobs can run concurrently on a
 * single thread with zero changes to job code.
 *
 * Best for: I/O-bound jobs (emails, HTTP calls, DB queries, cache, notifications)
 *
 * To opt in explicitly: public ?bool $isolated = false;
 */
class InlineExecutionStrategy implements ExecutionStrategy
{
    public function canHandle(KQueueJobInterface $job): bool
    {
        return $job->isIsolated() === false;
    }

    public function execute(KQueueJobInterface $job): void
    {
        $job->handle();
    }
}
