<?php

namespace KQueue\Queue;

use Illuminate\Contracts\Queue\Job;
use KQueue\Contracts\KQueueJobInterface;
use Illuminate\Support\Facades\Log;

/**
 * Adapts a Laravel Queue Job to KQueue's job interface
 *
 * This adapter wraps Laravel's Job instance and implements KQueueJobInterface,
 * allowing Laravel jobs to be executed through KQueue's event-loop runtime.
 */
class LaravelJobAdapter implements KQueueJobInterface
{
    private Job $laravelJob;
    private int $timeout;
    private int $maxMemory;
    private bool $isolated;
    private int $priority;
    private string $jobId;
    private int $defaultTimeout;
    private int $defaultMemory;
    private bool $defaultIsolated;

    public function __construct(
        Job $laravelJob,
        int $defaultTimeout = 60,
        int $defaultMemory = 128,
        bool $defaultIsolated = true  // Isolated by default for concurrent execution!
    ) {
        $this->laravelJob = $laravelJob;
        $this->defaultTimeout = $defaultTimeout;
        $this->defaultMemory = $defaultMemory;
        $this->defaultIsolated = $defaultIsolated;

        // Generate job ID from Laravel job UUID or ID
        $this->jobId = method_exists($laravelJob, 'uuid')
            ? $laravelJob->uuid()
            : $laravelJob->getJobId();

        // Extract job configuration from payload
        $this->extractJobConfiguration();
    }

    /**
     * Extract job configuration from Laravel job payload
     */
    private function extractJobConfiguration(): void
    {
        try {
            $payload = $this->laravelJob->payload();

            // Extract timeout
            $this->timeout = $payload['timeout'] ?? $this->defaultTimeout;

            // Try to resolve the actual job instance to get properties
            $command = $this->resolveCommand($payload);

            if ($command) {
                // Check for isolated property
                if (property_exists($command, 'isolated')) {
                    $this->isolated = (bool) $command->isolated;
                } else {
                    $this->isolated = $this->defaultIsolated;
                }

                // Check for maxMemory property
                if (property_exists($command, 'maxMemory')) {
                    $this->maxMemory = (int) $command->maxMemory;
                } else {
                    $this->maxMemory = $this->defaultMemory;
                }

                // Check for priority property
                if (property_exists($command, 'priority')) {
                    $this->priority = (int) $command->priority;
                } else {
                    $this->priority = 0;
                }
            } else {
                // Fallback to defaults
                $this->isolated = $this->defaultIsolated;
                $this->maxMemory = $this->defaultMemory;
                $this->priority = 0;
            }
        } catch (\Throwable $e) {
            // Fallback to defaults if extraction fails
            $this->timeout = $this->defaultTimeout;
            $this->maxMemory = $this->defaultMemory;
            $this->isolated = $this->defaultIsolated;
            $this->priority = 0;

            Log::warning('Failed to extract job configuration', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the command instance from payload
     */
    private function resolveCommand(array $payload): ?object
    {
        try {
            if (!isset($payload['data']['command'])) {
                return null;
            }

            $command = $payload['data']['command'];

            // Check if command is serialized
            if (is_string($command) && str_starts_with($command, 'O:')) {
                return unserialize($command);
            }

            // Check if command is encrypted (Laravel 8+)
            if (is_string($command) && function_exists('decrypt')) {
                try {
                    return unserialize(decrypt($command));
                } catch (\Throwable $e) {
                    // Not encrypted or decryption failed
                    return null;
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Execute the Laravel job
     *
     * This method is called by KQueue's execution strategies.
     * It delegates to Laravel's Job::fire() method which handles
     * deserialization and execution of the actual job handler.
     */
    public function handle(): void
    {
        $this->laravelJob->fire();
    }

    /**
     * Called when job executes successfully
     * Deletes the job from the queue
     */
    public function onSuccess(): void
    {
        if (!$this->laravelJob->isDeleted() && !$this->laravelJob->isReleased()) {
            $this->laravelJob->delete();

            Log::debug('Job completed successfully', [
                'job_id' => $this->jobId,
                'connection' => $this->laravelJob->getConnectionName(),
                'queue' => $this->laravelJob->getQueue(),
            ]);
        }
    }

    /**
     * Called when job fails
     * Releases job back to queue for retry or marks as failed
     *
     * @param \Throwable $exception The exception that caused the failure
     */
    public function onFailure(\Throwable $exception): void
    {
        if ($this->laravelJob->isDeleted() || $this->laravelJob->isReleased()) {
            return;
        }

        $maxTries = $this->laravelJob->maxTries();
        $attempts = $this->laravelJob->attempts();

        // Check if we should retry
        if ($maxTries === null || $attempts < $maxTries) {
            // Calculate backoff delay
            $delay = $this->calculateBackoff($attempts);

            // Release job back to queue
            $this->laravelJob->release($delay);

            Log::warning('Job failed, releasing for retry', [
                'job_id' => $this->jobId,
                'attempt' => $attempts,
                'max_tries' => $maxTries,
                'backoff_delay' => $delay,
                'error' => $exception->getMessage(),
            ]);
        } else {
            // Max attempts reached, mark as failed
            $this->laravelJob->fail($exception);

            Log::error('Job failed permanently', [
                'job_id' => $this->jobId,
                'attempts' => $attempts,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Calculate backoff delay for retries
     *
     * @param int $attempts Current attempt number
     * @return int Delay in seconds
     */
    private function calculateBackoff(int $attempts): int
    {
        try {
            $payload = $this->laravelJob->payload();

            // Check for custom backoff
            if (isset($payload['backoff'])) {
                $backoff = $payload['backoff'];

                if (is_array($backoff)) {
                    // Use attempt-specific backoff
                    return $backoff[$attempts - 1] ?? end($backoff);
                }

                if (is_numeric($backoff)) {
                    return (int) $backoff;
                }
            }
        } catch (\Throwable $e) {
            // Fallback to exponential backoff
        }

        // Exponential backoff: 2^attempts seconds (capped at 10 minutes)
        return min(pow(2, $attempts), 600);
    }

    /**
     * Check if job should be retried
     */
    public function shouldRetry(): bool
    {
        $maxTries = $this->laravelJob->maxTries();
        $attempts = $this->laravelJob->attempts();

        return $maxTries === null || $attempts < $maxTries;
    }

    /**
     * Get number of attempts
     */
    public function getAttempts(): int
    {
        return $this->laravelJob->attempts();
    }

    /**
     * Get Laravel job instance
     */
    public function getLaravelJob(): Job
    {
        return $this->laravelJob;
    }

    // KQueueJobInterface implementation

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
