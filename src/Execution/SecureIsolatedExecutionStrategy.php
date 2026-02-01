<?php

namespace KQueue\Execution;

use KQueue\Contracts\ExecutionStrategy;
use KQueue\Contracts\KQueueJobInterface;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;

/**
 * SECURE Isolated Execution Strategy
 * Fixes critical security vulnerabilities:
 * - No unsafe deserialization
 * - Path validation
 * - Proper timeout enforcement
 * - Memory limit enforcement
 * - Secure temp file handling
 */
class SecureIsolatedExecutionStrategy implements ExecutionStrategy
{
    private LoopInterface $loop;
    private array $allowedJobPaths;
    private int $maxTimeout;
    private int $maxMemory;

    public function __construct(
        ?LoopInterface $loop = null,
        array $allowedJobPaths = [],
        int $maxTimeout = 300,      // 5 minutes max
        int $maxMemory = 512        // 512MB max
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->allowedJobPaths = $allowedJobPaths;
        $this->maxTimeout = $maxTimeout;
        $this->maxMemory = $maxMemory;
    }

    public function canHandle(KQueueJobInterface $job): bool
    {
        return $job->isIsolated();
    }

    public function execute(KQueueJobInterface $job): PromiseInterface
    {
        $deferred = new Deferred();

        try {
            // SECURITY: Validate job properties server-side
            $this->validateJobProperties($job);

            // SECURITY: Validate job class file path
            $jobClass = get_class($job);
            $reflection = new \ReflectionClass($jobClass);
            $jobClassFile = $reflection->getFileName();

            if (!$this->isPathAllowed($jobClassFile)) {
                throw new \SecurityException(
                    "Job class file is not in allowed directory: {$jobClassFile}"
                );
            }

            // SECURITY: Use JSON instead of serialize() to prevent object injection
            $jobData = $this->serializeJobSecurely($job);

            // Create secure temporary file
            $tmpFile = $this->createSecureTempFile($jobClass, $jobClassFile, $jobData, $job);

            // Execute with timeout enforcement
            $this->executeWithTimeout($tmpFile, $job, $deferred);

        } catch (\Throwable $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }

    /**
     * SECURITY: Validate job properties to prevent DoS
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
     * SECURITY: Validate that file path is in allowed directories
     */
    private function isPathAllowed(string $path): bool
    {
        // If no allowed paths configured, allow anything (development mode)
        if (empty($this->allowedJobPaths)) {
            return true;
        }

        $realPath = realpath($path);

        if ($realPath === false) {
            return false;
        }

        foreach ($this->allowedJobPaths as $allowedDir) {
            $realAllowedDir = realpath($allowedDir);

            if ($realAllowedDir !== false && str_starts_with($realPath, $realAllowedDir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SECURITY: Use JSON instead of serialize() to prevent object injection
     */
    private function serializeJobSecurely(KQueueJobInterface $job): string
    {
        // Extract only serializable data (no objects)
        $jobData = [
            'class' => get_class($job),
            'properties' => $this->extractJobProperties($job),
            'timeout' => $job->getTimeout(),
            'maxMemory' => $job->getMaxMemory(),
        ];

        return base64_encode(json_encode($jobData, JSON_THROW_ON_ERROR));
    }

    /**
     * Extract public properties from job object (safe for JSON)
     */
    private function extractJobProperties(KQueueJobInterface $job): array
    {
        $reflection = new \ReflectionClass($job);
        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            // Skip internal properties
            if (in_array($name, ['timeout', 'maxMemory', 'isolated', 'priority'])) {
                continue;
            }

            $value = $property->getValue($job);

            // Only serialize scalar values and arrays (no objects)
            if (is_scalar($value) || is_array($value) || is_null($value)) {
                $properties[$name] = $value;
            }
        }

        return $properties;
    }

    /**
     * SECURITY: Create temp file with proper permissions
     */
    private function createSecureTempFile(
        string $jobClass,
        string $jobClassFile,
        string $jobData,
        KQueueJobInterface $job
    ): string {
        $tmpFile = tempnam(sys_get_temp_dir(), 'kqueue_job_');

        // SECURITY: Set restrictive permissions (owner read/write only)
        chmod($tmpFile, 0600);

        $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $memoryLimit = $job->getMaxMemory();

        // SECURITY: Sanitize paths for script
        $autoloadPath = addslashes($autoloadPath);
        $jobClassFile = addslashes($jobClassFile);
        $jobClass = addslashes($jobClass);

        // Create script that reconstructs job from JSON (safe)
        $script = <<<PHP
<?php
// SECURITY: Set memory limit
ini_set('memory_limit', '{$memoryLimit}M');

require_once '{$autoloadPath}';
require_once '{$jobClassFile}';

// SECURITY: Deserialize from JSON (not unserialize!)
\$jobData = json_decode(base64_decode('{$jobData}'), true, 512, JSON_THROW_ON_ERROR);

// Reconstruct job object
\$jobClass = \$jobData['class'];
\$job = new \$jobClass();

// Set properties
foreach (\$jobData['properties'] as \$name => \$value) {
    if (property_exists(\$job, \$name)) {
        \$job->\$name = \$value;
    }
}

try {
    \$job->handle();
    exit(0);
} catch (\Throwable \$e) {
    // SECURITY: Don't leak stack traces
    fwrite(STDERR, "Job failed: " . \$e->getMessage());
    exit(1);
}
PHP;

        file_put_contents($tmpFile, $script);

        return $tmpFile;
    }

    /**
     * SECURITY: Execute with enforced timeout (actually kill the process)
     */
    private function executeWithTimeout(
        string $tmpFile,
        KQueueJobInterface $job,
        Deferred $deferred
    ): void {
        $process = new Process('php ' . escapeshellarg($tmpFile));
        $process->start($this->loop);

        $stderr = '';
        $timeoutTimer = null;
        $isTimedOut = false;

        // SECURITY: Actually enforce timeout by killing the process
        $timeoutTimer = $this->loop->addTimer($job->getTimeout(), function() use ($process, &$isTimedOut) {
            if ($process->isRunning()) {
                $isTimedOut = true;
                $process->terminate(SIGKILL); // Hard kill
            }
        });

        $process->stdout->on('data', function($data) {
            // Forward stdout but don't echo sensitive data
            // In production, this should go to structured logs
        });

        $process->stderr->on('data', function($data) use (&$stderr) {
            $stderr .= $data;
        });

        $process->on('exit', function($exitCode) use ($deferred, &$stderr, $tmpFile, $timeoutTimer, &$isTimedOut) {
            // Cancel timeout timer
            if ($timeoutTimer) {
                $this->loop->cancelTimer($timeoutTimer);
            }

            // Clean up temp file securely
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }

            if ($isTimedOut) {
                $deferred->reject(new \RuntimeException('Job execution timed out and was killed'));
            } elseif ($exitCode === 0) {
                $deferred->resolve(null);
            } else {
                // SECURITY: Sanitize error message
                $error = 'Job execution failed with exit code ' . $exitCode;
                $deferred->reject(new \RuntimeException($error));
            }
        });
    }
}

/**
 * Custom Security Exception
 */
class SecurityException extends \RuntimeException {}
