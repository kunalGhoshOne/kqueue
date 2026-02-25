<?php

namespace KQueue\Execution;

use KQueue\Contracts\ExecutionStrategy;
use KQueue\Contracts\KQueueJobInterface;
use KQueue\Queue\LaravelJobAdapter;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;

/**
 * SECURE Isolated Execution Strategy for Laravel Jobs
 *
 * This strategy handles LaravelJobAdapter objects by executing
 * the job in a child process using Laravel's job system.
 */
class SecureLaravelIsolatedExecutionStrategy implements ExecutionStrategy
{
    private LoopInterface $loop;
    private int $maxTimeout;
    private int $maxMemory;

    public function __construct(
        ?LoopInterface $loop = null,
        int $maxTimeout = 300,      // 5 minutes max
        int $maxMemory = 512        // 512MB max
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->maxTimeout = $maxTimeout;
        $this->maxMemory = $maxMemory;
    }

    public function canHandle(KQueueJobInterface $job): bool
    {
        // Handle LaravelJobAdapter objects that need isolation
        return $job->isIsolated() && $job instanceof LaravelJobAdapter;
    }

    public function execute(KQueueJobInterface $job): PromiseInterface
    {
        $deferred = new Deferred();

        try {
            // Validate job properties
            $this->validateJobProperties($job);

            // Create a temporary PHP file that uses Laravel's job fire method
            $tmpFile = $this->createJobScript($job);

            // Execute with timeout enforcement
            $this->executeWithTimeout($tmpFile, $job, $deferred);

        } catch (\Throwable $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }

    /**
     * Validate job properties to prevent DoS
     */
    private function validateJobProperties(KQueueJobInterface $job): void
    {
        $timeout = $job->getTimeout();
        $memory = $job->getMaxMemory();

        if ($timeout <= 0 || $timeout > $this->maxTimeout) {
            throw new \InvalidArgumentException(
                "Job timeout must be between 1 and {$this->maxTimeout} seconds, got: {$timeout}"
            );
        }

        if ($memory <= 0 || $memory > $this->maxMemory) {
            throw new \InvalidArgumentException(
                "Job memory must be between 1 and {$this->maxMemory} MB, got: {$memory}"
            );
        }
    }

    /**
     * Create temporary PHP script to execute Laravel job
     */
    private function createJobScript(LaravelJobAdapter $jobAdapter): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'kqueue_laravel_job_');
        chmod($tmpFile, 0600);

        // Serialize the Laravel job and adapter data
        $laravelJob = $jobAdapter->getLaravelJob();
        $jobData = base64_encode(serialize($laravelJob));
        $memoryLimit = $jobAdapter->getMaxMemory();

        // Find Laravel base path
        $laravelBasePath = base_path();

        $script = <<<PHP
<?php
// Set memory limit
ini_set('memory_limit', '{$memoryLimit}M');

// Bootstrap Laravel
require_once '{$laravelBasePath}/vendor/autoload.php';
\$app = require_once '{$laravelBasePath}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Deserialize and execute Laravel job
try {
    \$laravelJob = unserialize(base64_decode('{$jobData}'));
    \$laravelJob->fire();
    exit(0);
} catch (\Throwable \$e) {
    fwrite(STDERR, "Job failed: " . \$e->getMessage() . "\\n");
    fwrite(STDERR, \$e->getTraceAsString());
    exit(1);
}
PHP;

        file_put_contents($tmpFile, $script);

        return $tmpFile;
    }

    /**
     * Execute with enforced timeout
     */
    private function executeWithTimeout(
        string $tmpFile,
        KQueueJobInterface $job,
        Deferred $deferred
    ): void {
        $process = new Process('php ' . escapeshellarg($tmpFile));
        $process->start($this->loop);

        $stderr = '';
        $stdout = '';
        $timeoutTimer = null;
        $isTimedOut = false;

        // Enforce timeout by killing the process
        $timeoutTimer = $this->loop->addTimer($job->getTimeout(), function() use ($process, &$isTimedOut) {
            if ($process->isRunning()) {
                $isTimedOut = true;
                $process->terminate(SIGKILL);
            }
        });

        $process->stdout->on('data', function($data) use (&$stdout) {
            $stdout .= $data;
            echo $data; // Forward output
        });

        $process->stderr->on('data', function($data) use (&$stderr) {
            $stderr .= $data;
        });

        $process->on('exit', function($exitCode) use ($deferred, &$stderr, $tmpFile, $timeoutTimer, &$isTimedOut) {
            // Cancel timeout timer
            if ($timeoutTimer) {
                $this->loop->cancelTimer($timeoutTimer);
            }

            // Clean up temp file
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }

            if ($isTimedOut) {
                $deferred->reject(new \RuntimeException("Job exceeded timeout and was killed"));
            } elseif ($exitCode === 0) {
                $deferred->resolve(null);
            } else {
                $error = $stderr ?: 'Process exited with code ' . $exitCode;
                $deferred->reject(new \RuntimeException($error));
            }
        });
    }
}
