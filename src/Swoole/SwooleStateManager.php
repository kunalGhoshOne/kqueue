<?php

namespace KQueue\Swoole;

/**
 * Per-job state isolation manager for Swoole coroutine execution.
 *
 * Automatically fixes the 4 main Swoole issues so developers don't have to:
 *
 *  1. Global state persistence  → snapshot globals before job, restore after
 *  2. Singleton leaks           → flush Laravel container instances before each job
 *  3. Non-hookable extensions   → detect at startup, auto-route to isolated processes
 *  4. sleep() outside coroutine → prevented by always booting inside Swoole\Coroutine\run()
 */
class SwooleStateManager
{
    private array $resettableAbstracts;
    private array $globalSnapshot = [];

    /**
     * Extensions that bypass PHP's stream layer and cannot be hooked by Swoole.
     * Jobs using these are automatically routed to IsolatedExecutionStrategy
     * (separate process) where hooks are irrelevant.
     */
    private const NON_HOOKABLE_EXTENSIONS = [
        'mongo',   // Old mongo driver — ext-mongodb (the modern driver) IS hookable
        'sqlsrv',  // Some SQL Server configurations bypass the stream layer
    ];

    /**
     * Laravel singletons that carry per-request/per-job state.
     * Flushed before each job so every job starts with a clean container.
     * Developers can extend this list via config/kqueue.php.
     */
    private const DEFAULT_RESETTABLE = [
        'auth',
        'auth.driver',
        'db',
        'db.connection',
        'cache',
        'cache.store',
        'session',
        'session.store',
    ];

    public function __construct(array $resettableAbstracts = [])
    {
        $this->resettableAbstracts = $resettableAbstracts ?: self::DEFAULT_RESETTABLE;
    }

    /**
     * Called inside each job coroutine before handle() runs.
     * Snapshots globals and flushes stale singletons.
     */
    public function prepareForJob(): void
    {
        $this->snapshotGlobals();
        $this->flushSingletons();
    }

    /**
     * Called inside each job coroutine after handle() finishes (success or fail).
     * Restores globals to prevent leaking state into the next job.
     */
    public function cleanupAfterJob(): void
    {
        $this->restoreGlobals();
    }

    /**
     * Scan installed extensions for known non-hookable ones.
     * Call this at worker startup to warn developers early.
     *
     * @return string[] Installed extension names that Swoole cannot hook
     */
    public static function detectNonHookableExtensions(): array
    {
        return array_values(array_filter(
            self::NON_HOOKABLE_EXTENSIONS,
            fn(string $ext) => extension_loaded($ext)
        ));
    }

    /**
     * Snapshot scalar globals before a job runs.
     * Objects and resources are skipped — deep-cloning them reliably is not possible.
     */
    private function snapshotGlobals(): void
    {
        $this->globalSnapshot = [];

        foreach ($GLOBALS as $key => $value) {
            if (!is_object($value) && !is_resource($value)) {
                $this->globalSnapshot[$key] = $value;
            }
        }
    }

    /**
     * Restore global variables to the pre-job snapshot.
     */
    private function restoreGlobals(): void
    {
        foreach ($this->globalSnapshot as $key => $value) {
            $GLOBALS[$key] = $value;
        }

        $this->globalSnapshot = [];
    }

    /**
     * Flush known singleton instances from Laravel's IoC container.
     * The next job will receive a fresh instance through the existing binding.
     */
    private function flushSingletons(): void
    {
        if (!function_exists('app')) {
            return;
        }

        $app = app();

        foreach ($this->resettableAbstracts as $abstract) {
            try {
                $app->forgetInstance($abstract);
            } catch (\Throwable) {
                // Instance may not be bound — safe to ignore
            }
        }
    }
}
