<?php

namespace KQueue\Execution;

use KQueue\Contracts\ExecutionStrategy;
use KQueue\Contracts\KQueueJobInterface;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;

/**
 * Executes jobs inline within the event loop
 * Best for fast, non-blocking jobs
 */
class InlineExecutionStrategy implements ExecutionStrategy
{
    public function canHandle(KQueueJobInterface $job): bool
    {
        // Handle non-isolated jobs
        return !$job->isIsolated();
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
