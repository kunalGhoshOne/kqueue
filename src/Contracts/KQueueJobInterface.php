<?php

namespace KQueue\Contracts;

interface KQueueJobInterface
{
    /**
     * Execute the job
     */
    public function handle(): void;

    /**
     * Get job timeout in seconds
     */
    public function getTimeout(): int;

    /**
     * Get max memory in MB
     */
    public function getMaxMemory(): int;

    /**
     * Should this job run isolated (in separate process)?
     */
    public function isIsolated(): bool;

    /**
     * Get job priority (higher = more important)
     */
    public function getPriority(): int;

    /**
     * Get job identifier
     */
    public function getJobId(): string;
}
