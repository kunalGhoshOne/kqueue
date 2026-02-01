<?php

namespace KQueue\Jobs;

use KQueue\Contracts\KQueueJobInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

abstract class KQueueJob implements KQueueJobInterface, ShouldQueue
{
    /**
     * Job timeout in seconds
     */
    public int $timeout = 30;

    /**
     * Maximum memory in MB
     */
    public int $maxMemory = 64;

    /**
     * Should run in isolated process?
     */
    public bool $isolated = false;

    /**
     * Job priority (higher = more important)
     */
    public int $priority = 0;

    /**
     * Unique job identifier
     */
    private string $jobId;

    public function __construct()
    {
        $this->jobId = uniqid('job_', true);
    }

    /**
     * Execute the job (to be implemented by user)
     */
    abstract public function handle(): void;

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getMaxMemory(): int
    {
        return $this->maxMemory;
    }

    public function isIsolated(): bool
    {
        return $this->isolated;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }
}
