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
use Illuminate\Support\Facades\Log;

/**
 * Laravel queue worker for KQueue.
 *
 * Boots Swoole's coroutine runtime, enables SWOOLE_HOOK_ALL so all blocking
 * PHP calls become non-blocking, then polls the queue using a Swoole timer.
 * Each job is dispatched to the KQueue runtime which spawns a coroutine —
 * no code changes required in existing Laravel jobs.
 *
 * Fixes all Swoole pitfalls automatically via SwooleStateManager:
 *  - Global state reset per job
 *  - Singleton flush per job
 *  - Non-hookable extension detection at startup
 *  - Coroutine context always guaranteed
 */
class LaravelQueueWorker
{
    private KQueueRuntime|SecureKQueueRuntime|SmartKQueueRuntime $runtime;
    private QueueManager  $queueManager;
    private ?Dispatcher   $events;

    private string $connection;
    private string $queue;
    private int    $sleep;     // milliseconds
    private int    $maxJobs;
    private int    $maxTime;   // seconds
    private int    $defaultTimeout;
    private int    $defaultMemory;
    private bool   $defaultIsolated;

    private int    $jobsProcessed = 0;
    private float  $startTime;
    private bool   $shouldQuit   = false;
    private ?int   $pollTimerId  = null;

    public function __construct(
        KQueueRuntime|SecureKQueueRuntime|SmartKQueueRuntime $runtime,
        QueueManager $queueManager,
        ?Dispatcher $events = null,
        array $options = []
    ) {
        $this->runtime      = $runtime;
        $this->queueManager = $queueManager;
        $this->events       = $events;

        $this->connection      = $options['connection']      ?? 'redis';
        $this->queue           = $options['queue']           ?? 'default';
        $this->sleep           = $options['sleep']           ?? 100;
        $this->maxJobs         = $options['maxJobs']         ?? 0;
        $this->maxTime         = $options['maxTime']         ?? 0;
        $this->defaultTimeout  = $options['defaultTimeout']  ?? 60;
        $this->defaultMemory   = $options['defaultMemory']   ?? 128;
        $this->defaultIsolated = $options['defaultIsolated'] ?? true;

        $this->startTime = microtime(true);
    }

    /**
     * Start the worker.
     *
     * Enables SWOOLE_HOOK_ALL then enters Swoole\Coroutine\run() — a long-lived
     * coroutine context where all I/O is non-blocking. This call blocks until
     * the worker is stopped (via stop() or a signal).
     */
    public function work(): void
    {
        // Issue 4 fix: ensure we are always inside a coroutine context.
        // SWOOLE_HOOK_ALL makes sleep/DB/HTTP/file non-blocking everywhere.
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        \Swoole\Coroutine\run(function () {
            $this->registerSignalHandlers();
            $this->runtime->start();

            Log::info('KQueue worker started', [
                'connection' => $this->connection,
                'queue'      => $this->queue,
                'sleep'      => $this->sleep . 'ms',
                'maxJobs'    => $this->maxJobs  ?: 'unlimited',
                'maxTime'    => $this->maxTime  ? $this->maxTime . 's' : 'unlimited',
            ]);

            $this->pollTimerId = \Swoole\Timer::tick($this->sleep, function () {
                if (!$this->shouldContinue()) {
                    $this->stop();
                    return;
                }

                $this->poll();
            });
        });

        Log::info('KQueue worker stopped', [
            'jobs_processed' => $this->jobsProcessed,
            'runtime'        => round(microtime(true) - $this->startTime, 2) . 's',
        ]);
    }

    private function poll(): void
    {
        $this->raiseEvent(new Looping($this->connection, $this->queue));

        try {
            $queueConnection = $this->queueManager->connection($this->connection);
            $laravelJob      = $queueConnection->pop($this->queue);

            if ($laravelJob instanceof Job) {
                $this->processJob($laravelJob);
            }
        } catch (\Throwable $e) {
            // Ignore "connection closed" errors that occur when the worker stops
            if (str_contains($e->getMessage(), 'connection is closed')
                || str_contains($e->getMessage(), 'socket was already closed')
                || $this->shouldQuit) {
                return;
            }

            Log::error('Error during queue poll', [
                'connection' => $this->connection,
                'queue'      => $this->queue,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function processJob(Job $laravelJob): void
    {
        try {
            $adapter = new LaravelJobAdapter(
                $laravelJob,
                $this->defaultTimeout,
                $this->defaultMemory,
                $this->defaultIsolated
            );

            $this->raiseEvent(new JobProcessing($this->connection, $laravelJob));

            Log::debug('Dispatching job to KQueue runtime', [
                'job_id'   => $adapter->getJobId(),
                'job_name' => $laravelJob->getName(),
                'attempt'  => $adapter->getAttempts(),
            ]);

            // Spawn coroutine — returns immediately, job runs concurrently
            $this->runtime->executeJob(
                $adapter,
                function () use ($adapter, $laravelJob) {
                    $adapter->onSuccess();
                    $this->jobsProcessed++;
                    $this->raiseEvent(new JobProcessed($this->connection, $laravelJob));
                    Log::info('Job completed', ['job_id' => $adapter->getJobId()]);
                },
                function (\Throwable $e) use ($adapter, $laravelJob) {
                    $adapter->onFailure($e);
                    $this->raiseEvent(new JobFailed($this->connection, $laravelJob, $e));
                    Log::error('Job failed', [
                        'job_id' => $adapter->getJobId(),
                        'error'  => $e->getMessage(),
                    ]);
                }
            );
        } catch (\Throwable $e) {
            Log::error('Error setting up job', [
                'error' => $e->getMessage(),
            ]);

            if (!$laravelJob->isDeleted() && !$laravelJob->isReleased()) {
                $laravelJob->release(60);
            }
        }
    }

    private function shouldContinue(): bool
    {
        if ($this->shouldQuit) {
            return false;
        }

        if ($this->maxJobs > 0 && $this->jobsProcessed >= $this->maxJobs) {
            Log::info('Max jobs limit reached', ['jobs_processed' => $this->jobsProcessed]);
            return false;
        }

        if ($this->maxTime > 0 && (microtime(true) - $this->startTime) >= $this->maxTime) {
            Log::info('Max time limit reached', ['elapsed' => round(microtime(true) - $this->startTime, 2) . 's']);
            return false;
        }

        return true;
    }

    public function stop(): void
    {
        if ($this->shouldQuit) {
            return;
        }

        $this->shouldQuit = true;

        if ($this->pollTimerId !== null) {
            \Swoole\Timer::clear($this->pollTimerId);
            $this->pollTimerId = null;
        }

        $this->raiseEvent(new WorkerStopping());
        $this->runtime->stop();
    }

    /**
     * Use Swoole\Process::signal() for coroutine-safe signal handling.
     * pcntl_signal() is not safe inside Swoole coroutines.
     */
    private function registerSignalHandlers(): void
    {
        \Swoole\Process::signal(SIGTERM, function () {
            Log::info('Received SIGTERM, stopping worker gracefully');
            $this->stop();
        });

        \Swoole\Process::signal(SIGINT, function () {
            Log::info('Received SIGINT, stopping worker gracefully');
            $this->stop();
        });
    }

    private function raiseEvent(object $event): void
    {
        if ($this->events === null) {
            return;
        }

        try {
            $this->events->dispatch($event);
        } catch (\Throwable $e) {
            Log::error('Error dispatching event', [
                'event' => get_class($event),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getStats(): array
    {
        return [
            'connection'     => $this->connection,
            'queue'          => $this->queue,
            'jobs_processed' => $this->jobsProcessed,
            'runtime'        => round(microtime(true) - $this->startTime, 2),
            'should_quit'    => $this->shouldQuit,
        ];
    }
}
