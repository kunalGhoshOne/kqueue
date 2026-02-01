<?php

namespace KQueue\Contracts;

use React\Promise\PromiseInterface;

interface ExecutionStrategy
{
    /**
     * Execute a job and return a promise
     */
    public function execute(KQueueJobInterface $job): PromiseInterface;

    /**
     * Check if this strategy can handle the job
     */
    public function canHandle(KQueueJobInterface $job): bool;
}
