<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection
    |--------------------------------------------------------------------------
    */
    'default_connection' => env('KQUEUE_CONNECTION', env('QUEUE_CONNECTION', 'redis')),

    /*
    |--------------------------------------------------------------------------
    | Default Queue Name
    |--------------------------------------------------------------------------
    */
    'default_queue' => env('KQUEUE_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Runtime Configuration
    |--------------------------------------------------------------------------
    */
    'runtime' => [
        'memory_limit' => (int) env('KQUEUE_MEMORY', 512),

        // Smart runtime: automatically detects the best strategy per job
        'smart'  => env('KQUEUE_SMART', true),

        // Secure runtime: adds validation, rate limiting, sanitized logging
        'secure' => env('KQUEUE_SECURE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Swoole Configuration
    |--------------------------------------------------------------------------
    |
    | KQueue enables SWOOLE_HOOK_ALL automatically — sleep(), DB, HTTP, file
    | I/O become non-blocking with zero changes to your job code.
    |
    | SwooleStateManager resets these Laravel singletons before each job to
    | prevent state leaking between jobs. Add your own service container
    | bindings to this list if you observe stale state in your application.
    |
    */
    'swoole' => [
        'resettable_singletons' => [
            'auth',
            'auth.driver',
            'db',
            'db.connection',
            'cache',
            'cache.store',
            'session',
            'session.store',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | The JobAnalyzer estimates how long a job will run and selects the
    | appropriate execution strategy automatically.
    |
    */
    'analysis' => [
        // Jobs estimated <= this run INLINE as coroutines (I/O-bound, fast)
        'inline_threshold' => (float) env('KQUEUE_INLINE_THRESHOLD', 1.0),

        // Jobs estimated <= this run in a POOLED process
        'pooled_threshold' => (float) env('KQUEUE_POOLED_THRESHOLD', 30.0),

        // Jobs over pooled_threshold run ISOLATED (dedicated process)
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Configuration
    |--------------------------------------------------------------------------
    */
    'worker' => [
        // How often to poll the queue (milliseconds)
        'sleep'    => 100,

        // Max jobs before worker restarts (0 = unlimited)
        'max_jobs' => 0,

        // Max seconds before worker restarts (0 = unlimited)
        'max_time' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Configuration
    |--------------------------------------------------------------------------
    */
    'jobs' => [
        'default_timeout'    => 60,
        'default_memory'     => 128,

        // Run all jobs in isolated processes by default (true = concurrent)
        // Set to false for sequential/inline by default
        'isolated_by_default' => true,

        // Server-side hard limits — jobs cannot exceed these
        'max_timeout'    => 300,
        'max_memory'     => 512,
        'max_concurrent' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    */
    'security' => [
        // Whitelist for isolated job class files.
        // Empty = allow all (fine for development).
        // Set to [app_path('Jobs')] in production.
        'allowed_job_paths' => [
            app_path('Jobs'),
        ],

        // Max jobs accepted per minute (rate limiting)
        'max_jobs_per_minute' => 1000,
    ],

];
