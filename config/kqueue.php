<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection
    |--------------------------------------------------------------------------
    |
    | The queue connection to use when none is specified. This should match
    | a connection defined in your config/queue.php file.
    |
    */

    'default_connection' => env('KQUEUE_CONNECTION', env('QUEUE_CONNECTION', 'redis')),

    /*
    |--------------------------------------------------------------------------
    | Default Queue Name
    |--------------------------------------------------------------------------
    |
    | The default queue to process jobs from when none is specified.
    |
    */

    'default_queue' => env('KQUEUE_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Runtime Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the KQueue runtime behavior.
    |
    */

    'runtime' => [
        // Total memory limit for the runtime in MB
        'memory_limit' => (int) env('KQUEUE_MEMORY', 512),

        // Use smart runtime with automatic strategy selection
        // TRUE = Automatic detection of job types (RECOMMENDED!)
        // FALSE = Manual strategy selection via job properties
        'smart' => env('KQUEUE_SMART', true),

        // Use secure runtime with hardened security features
        'secure' => env('KQUEUE_SECURE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Configure thresholds for automatic job analysis and strategy selection.
    | The analyzer determines optimal execution mode based on job characteristics.
    |
    */

    'analysis' => [
        // Jobs with estimated duration <= this run INLINE (same process)
        // Default: 1.0 second
        'inline_threshold' => (float) env('KQUEUE_INLINE_THRESHOLD', 1.0),

        // Jobs with estimated duration <= this run in WORKER POOL
        // Default: 30.0 seconds
        'pooled_threshold' => (float) env('KQUEUE_POOLED_THRESHOLD', 30.0),

        // Jobs over pooled_threshold run ISOLATED (dedicated process)
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Configure worker behavior and resource limits.
    |
    */

    'worker' => [
        // Poll interval in milliseconds (how often to check for new jobs)
        'sleep' => 100,

        // Maximum number of jobs to process before restarting (0 = unlimited)
        'max_jobs' => 0,

        // Maximum time to run before restarting in seconds (0 = unlimited)
        'max_time' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Configuration
    |--------------------------------------------------------------------------
    |
    | Default settings and server-side limits for job execution.
    |
    */

    'jobs' => [
        // Default timeout for jobs in seconds (if not specified by job)
        'default_timeout' => 60,

        // Default memory limit for jobs in MB (if not specified by job)
        'default_memory' => 128,

        // Run all jobs in isolated processes by default
        // TRUE = Concurrent execution (KQueue's main value!)
        // FALSE = Sequential inline execution (opt-in for lightweight jobs)
        'isolated_by_default' => true,

        // Server-side maximum timeout (cannot be exceeded by jobs)
        'max_timeout' => 300,

        // Server-side maximum memory (cannot be exceeded by jobs)
        'max_memory' => 512,

        // Maximum concurrent jobs
        'max_concurrent' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security settings for production deployments.
    |
    */

    'security' => [
        // Allowed paths for job class files (whitelist for isolated execution)
        // Empty array allows all paths (development only!)
        'allowed_job_paths' => [
            app_path('Jobs'),
        ],

        // Rate limiting: Maximum jobs per minute
        'max_jobs_per_minute' => 1000,
    ],

];
