<?php

namespace KQueue\Execution;

use KQueue\Contracts\ExecutionStrategy;
use KQueue\Contracts\KQueueJobInterface;
use KQueue\Queue\LaravelJobAdapter;

/**
 * Secure isolated execution for non-Laravel KQueueJob classes.
 *
 * Validates the job class file is within allowed paths before spawning a
 * child process. Uses JSON serialization instead of PHP serialize() to
 * prevent object injection attacks.
 *
 * The coroutine suspends non-blocking while the child process runs —
 * other jobs keep running concurrently. Server-side timeout and memory
 * limits are enforced and cannot be exceeded by jobs.
 */
class SecureIsolatedExecutionStrategy implements ExecutionStrategy
{
    private array $allowedJobPaths;
    private int   $maxTimeout;
    private int   $maxMemory;

    public function __construct(
        array $allowedJobPaths = [],
        int $maxTimeout = 300,
        int $maxMemory = 512
    ) {
        $this->allowedJobPaths = $allowedJobPaths;
        $this->maxTimeout      = $maxTimeout;
        $this->maxMemory       = $maxMemory;
    }

    public function canHandle(KQueueJobInterface $job): bool
    {
        return $job->isIsolated() !== false && !($job instanceof LaravelJobAdapter);
    }

    public function execute(KQueueJobInterface $job): void
    {
        $this->validateJobProperties($job);
        $this->validateJobClassPath($job);

        $timeout = min($job->getTimeout(), $this->maxTimeout);
        $tmpFile = $this->createJobScript($job);

        // Coroutine suspends here — non-blocking wait
        $result = \Swoole\Coroutine\System::exec(
            sprintf('timeout %d php %s 2>&1', $timeout, escapeshellarg($tmpFile))
        );

        @unlink($tmpFile);

        if ($result['code'] === 124) {
            throw new \RuntimeException("Job timed out after {$timeout} seconds and was killed");
        }

        if ($result['code'] !== 0) {
            // Sanitize error — no stack traces to external callers
            throw new \RuntimeException("Job execution failed with exit code {$result['code']}");
        }
    }

    private function validateJobProperties(KQueueJobInterface $job): void
    {
        $timeout = $job->getTimeout();
        if ($timeout <= 0 || $timeout > $this->maxTimeout) {
            throw new \InvalidArgumentException(
                "Job timeout must be between 1 and {$this->maxTimeout} seconds, got: {$timeout}"
            );
        }

        $memory = $job->getMaxMemory();
        if ($memory <= 0 || $memory > $this->maxMemory) {
            throw new \InvalidArgumentException(
                "Job memory must be between 1 and {$this->maxMemory} MB, got: {$memory}"
            );
        }
    }

    private function validateJobClassPath(KQueueJobInterface $job): void
    {
        if (empty($this->allowedJobPaths)) {
            return; // No restriction — development mode
        }

        $reflection = new \ReflectionClass($job);
        $jobFile    = realpath($reflection->getFileName());

        foreach ($this->allowedJobPaths as $allowedDir) {
            $resolved = realpath($allowedDir);
            if ($resolved && str_starts_with($jobFile, $resolved)) {
                return;
            }
        }

        throw new \RuntimeException(
            sprintf('Job class file is outside allowed paths: %s', $jobFile)
        );
    }

    private function createJobScript(KQueueJobInterface $job): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'kqueue_secure_job_');
        chmod($tmpFile, 0600);

        $jobClass     = get_class($job);
        $reflection   = new \ReflectionClass($jobClass);
        $jobClassFile = $reflection->getFileName();
        $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $memoryLimit  = min($job->getMaxMemory(), $this->maxMemory);

        // Use JSON instead of serialize() to prevent object injection
        $jobData = base64_encode(json_encode([
            'class'      => $jobClass,
            'properties' => $this->extractScalarProperties($job),
        ], JSON_THROW_ON_ERROR));

        $script = <<<PHP
<?php
ini_set('memory_limit', '{$memoryLimit}M');
require_once '{$autoloadPath}';
require_once '{$jobClassFile}';
\$data = json_decode(base64_decode('{$jobData}'), true, 512, JSON_THROW_ON_ERROR);
\$job  = new \$data['class']();
foreach (\$data['properties'] as \$prop => \$value) {
    if (property_exists(\$job, \$prop)) {
        \$job->\$prop = \$value;
    }
}
try {
    \$job->handle();
    exit(0);
} catch (\Throwable \$e) {
    fwrite(STDERR, \$e->getMessage() . "\\n");
    exit(1);
}
PHP;

        file_put_contents($tmpFile, $script);

        return $tmpFile;
    }

    private function extractScalarProperties(KQueueJobInterface $job): array
    {
        $properties = [];
        $reflection = new \ReflectionClass($job);
        $skip       = ['timeout', 'maxMemory', 'isolated', 'priority'];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (in_array($prop->getName(), $skip, true)) {
                continue;
            }

            $value = $prop->getValue($job);

            if (is_scalar($value) || is_array($value) || is_null($value)) {
                $properties[$prop->getName()] = $value;
            }
        }

        return $properties;
    }
}
