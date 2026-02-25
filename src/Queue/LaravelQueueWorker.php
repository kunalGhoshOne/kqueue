<?php

namespace KQueue\Queue;

use Illuminate\Queue\QueueManager;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use KQueue\Runtime\KQueueRuntime;
use KQueue\Runtime\SecureKQueueRuntime;
use KQueue\Runtime\SmartKQueueRuntime;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Illuminate\Support\Facades\Log;

/**
 * Laravel Queue Worker for KQueue
 *
 * Polls Laravel queues and dispatches jobs to KQueue runtime for execution.
 * Uses timer-based polling to avoid blocking the event loop.
 */
class LaravelQueueWorker
{
    private KQueueRuntime|SecureKQueueRuntime|SmartKQueueRuntime $runtime;
    private QueueManager $queueManager;
    private ?Dispatcher $events;
    private LoopInterface $loop;

    private string $connection;
    private string $queue;
    private int $sleep; // milliseconds
    private int $maxJobs;
    private int $maxTime; // seconds
    private int $defaultTimeout;
    private int $defaultMemory;
    private bool $defaultIsolated;

    private int $jobsProcessed = 0;
    private float $startTime;
    private bool $shouldQuit = false;
    private ?TimerInterface $pollTimer = null;
    private array $stopCallbacks = [];

    public function __construct(
        KQueueRuntime|SecureKQueueRuntime|SmartKQueueRuntime $runtime,
        QueueManager $queueManager,
        ?Dispatcher $events = null,
        array $options = []
    ) {
        $this->runtime = $runtime;
        $this->queueManager = $queueManager;
        $this->events = $events;
        $this->loop = $runtime->getLoop();

        // Worker options
        $this->connection = $options['connection'] ?? 'redis';
        $this->queue = $options['queue'] ?? 'default';
        $this->sleep = $options['sleep'] ?? 100; // 100ms default
        $this->maxJobs = $options['maxJobs'] ?? 0;
        $this->maxTime = $options['maxTime'] ?? 0;

        // Job defaults
        $this->defaultTimeout = $options['defaultTimeout'] ?? 60;
        $this->defaultMemory = $options['defaultMemory'] ?? 128;
        $this->defaultIsolated = $options['defaultIsolated'] ?? true; // Isolated by default for concurrency!

        $this->startTime = microtime(true);
    }

    /**
     * Start the worker loop
     */
    public function work(): void
    {
        // Register signal handlers for graceful shutdown
        $this->registerStopListener();

        // Start polling
        $this->schedulePoll();

        Log::info('KQueue worker started', [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'sleep' => $this->sleep . 'ms',
            'maxJobs' => $this->maxJobs ?: 'unlimited',
            'maxTime' => $this->maxTime ? $this->maxTime . 's' : 'unlimited',
        ]);

        // Start the event loop (blocks until stopped)
        $this->runtime->start();

        Log::info('KQueue worker stopped', [
            'jobs_processed' => $this->jobsProcessed,
            'runtime' => round(microtime(true) - $this->startTime, 2) . 's',
        ]);
    }

    /**
     * Schedule next poll iteration
     *
     * @param bool $immediate If true, poll immediately without delay
     */
    private function schedulePoll(bool $immediate = false): void
    {
        if ($this->shouldQuit) {
            return;
        }

        $delay = $immediate ? 0 : ($this->sleep / 1000); // Convert ms to seconds

        $this->pollTimer = $this->loop->addTimer($delay, function () {
            $this->poll();
        });
    }

    /**
     * Single poll iteration
     * Checks queue for jobs and processes them
     */
    private function poll(): void
    {
        // Check if should continue
        if (!$this->shouldContinue()) {
            $this->stop();
            return;
        }

        // Raise looping event
        $this->raiseEvent(new Looping($this->connection, $this->queue));

        try {
            // Get queue connection
            $queueConnection = $this->queueManager->connection($this->connection);

            // Pop next job from queue
            $laravelJob = $queueConnection->pop($this->queue);

            if ($laravelJob instanceof Job) {
                // Process the job
                $this->processJob($laravelJob);

                // Schedule immediate next poll (more jobs might be available)
                $this->schedulePoll(true);
            } else {
                // No jobs available, schedule next poll with delay
                $this->schedulePoll(false);
            }
        } catch (\Throwable $e) {
            Log::error('Error during poll', [
                'connection' => $this->connection,
                'queue' => $this->queue,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Continue polling despite error
            $this->schedulePoll(false);
        }
    }

    /**
     * Process a single job
     *
     * @param Job $laravelJob The Laravel job to process
     */
    private function processJob(Job $laravelJob): void
    {
        try {
            // Wrap Laravel job in adapter
            $adapter = new LaravelJobAdapter(
                $laravelJob,
                $this->defaultTimeout,
                $this->defaultMemory,
                $this->defaultIsolated
            );

            // Raise job processing event
            $this->raiseEvent(new JobProcessing($this->connection, $laravelJob));

            Log::debug('Processing job', [
                'job_id' => $adapter->getJobId(),
                'job_name' => $laravelJob->getName(),
                'attempt' => $adapter->getAttempts(),
                'queue' => $this->queue,
            ]);

            // Execute job via KQueue runtime
            $promise = $this->runtime->executeJob($adapter);

            // Handle successful execution
            $promise->then(
                function () use ($adapter, $laravelJob) {
                    $adapter->onSuccess();
                    $this->jobsProcessed++;

                    // Raise job processed event
                    $this->raiseEvent(new JobProcessed($this->connection, $laravelJob));

                    Log::info('Job completed', [
                        'job_id' => $adapter->getJobId(),
                        'jobs_processed' => $this->jobsProcessed,
                    ]);
                },
                function (\Throwable $exception) use ($adapter, $laravelJob) {
                    $adapter->onFailure($exception);

                    // Raise job failed event
                    $this->raiseEvent(new JobFailed($this->connection, $laravelJob, $exception));

                    Log::error('Job failed', [
                        'job_id' => $adapter->getJobId(),
                        'error' => $exception->getMessage(),
                        'attempt' => $adapter->getAttempts(),
                    ]);
                }
            );
        } catch (\Throwable $e) {
            // Handle errors in job processing setup
            Log::error('Error setting up job', [
                'job_id' => $laravelJob->getJobId(),
                'error' => $e->getMessage(),
            ]);

            // Release job back to queue
            if (!$laravelJob->isDeleted() && !$laravelJob->isReleased()) {
                $laravelJob->release(60);
            }
        }
    }

    /**
     * Check if worker should continue processing
     */
    private function shouldContinue(): bool
    {
        // Check quit flag
        if ($this->shouldQuit) {
            return false;
        }

        // Check max jobs limit
        if ($this->maxJobs > 0 && $this->jobsProcessed >= $this->maxJobs) {
            Log::info('Max jobs limit reached', [
                'jobs_processed' => $this->jobsProcessed,
                'max_jobs' => $this->maxJobs,
            ]);
            return false;
        }

        // Check max time limit
        if ($this->maxTime > 0) {
            $elapsed = microtime(true) - $this->startTime;
            if ($elapsed >= $this->maxTime) {
                Log::info('Max time limit reached', [
                    'elapsed' => round($elapsed, 2) . 's',
                    'max_time' => $this->maxTime . 's',
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Register signal handlers for graceful shutdown
     */
    private function registerStopListener(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () {
                Log::info('Received SIGTERM, stopping worker gracefully');
                $this->stop();
            });

            pcntl_signal(SIGINT, function () {
                Log::info('Received SIGINT, stopping worker gracefully');
                $this->stop();
            });
        }
    }

    /**
     * Stop the worker gracefully
     */
    public function stop(): void
    {
        if ($this->shouldQuit) {
            return;
        }

        $this->shouldQuit = true;

        // Cancel pending poll timer
        if ($this->pollTimer !== null) {
            $this->loop->cancelTimer($this->pollTimer);
            $this->pollTimer = null;
        }

        // Raise worker stopping event
        $this->raiseEvent(new WorkerStopping());

        // Execute stop callbacks
        foreach ($this->stopCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::error('Error in stop callback', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Stop the runtime
        $this->runtime->stop();
    }

    /**
     * Register a callback to be executed when worker stops
     */
    public function onStop(callable $callback): void
    {
        $this->stopCallbacks[] = $callback;
    }

    /**
     * Raise a worker event
     *
     * @param object $event The event to raise
     */
    private function raiseEvent(object $event): void
    {
        if ($this->events !== null) {
            try {
                $this->events->dispatch($event);
            } catch (\Throwable $e) {
                Log::error('Error dispatching event', [
                    'event' => get_class($event),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get worker statistics
     */
    public function getStats(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'jobs_processed' => $this->jobsProcessed,
            'runtime' => round(microtime(true) - $this->startTime, 2),
            'should_quit' => $this->shouldQuit,
        ];
    }
}
