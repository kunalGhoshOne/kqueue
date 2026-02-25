<?php

namespace KQueue\Execution;

use KQueue\Contracts\ExecutionStrategy;
use KQueue\Contracts\KQueueJobInterface;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;

/**
 * Executes jobs inline within the event loop (SEQUENTIAL)
 *
 * This is an OPT-IN strategy for lightweight jobs that don't need concurrency.
 * Jobs must explicitly set: public bool $isolated = false;
 *
 * Best for: Fast I/O operations, HTTP requests, database queries
 */
class InlineExecutionStrategy implements ExecutionStrategy
{
    public function canHandle(KQueueJobInterface $job): bool
    {
        // Only handle jobs that explicitly opt-out of isolation
        return $job->isIsolated() === false;
    }

    public function execute(KQueueJobInterface $job): PromiseInterface
    {
        $deferred = new Deferred();

        try {
            // Execute the job
            $job->handle();

            // Resolve immediately (synchronous execution)
            $deferred->resolve(null);
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }
}
