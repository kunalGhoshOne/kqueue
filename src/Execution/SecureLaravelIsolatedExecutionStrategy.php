<?php

namespace KQueue\Execution;

use KQueue\Contracts\ExecutionStrategy;
use KQueue\Contracts\KQueueJobInterface;
use KQueue\Queue\LaravelJobAdapter;

/**
 * Secure isolated execution for Laravel jobs (LaravelJobAdapter).
 *
 * Bootstraps a full Laravel application in a child process and executes
 * the job with proper dependency injection. The coroutine suspends
 * non-blocking while waiting — all other jobs keep running.
 *
 * Enforces server-side timeout and memory limits. Validates timeout
 * and memory before spawning to prevent DoS via malformed jobs.
 */
class SecureLaravelIsolatedExecutionStrategy implements ExecutionStrategy
{
    private int $maxTimeout;
    private int $maxMemory;

    public function __construct(int $maxTimeout = 300, int $maxMemory = 512)
    {
        $this->maxTimeout = $maxTimeout;
        $this->maxMemory  = $maxMemory;
    }

    public function canHandle(KQueueJobInterface $job): bool
    {
        return $job->isIsolated() !== false && $job instanceof LaravelJobAdapter;
    }

    public function execute(KQueueJobInterface $job): void
    {
        $this->validateJobProperties($job);

        $timeout = min($job->getTimeout(), $this->maxTimeout);
        $tmpFile = $this->createJobScript($job);

        // Coroutine suspends here — non-blocking wait for child process
        $result = \Swoole\Coroutine\System::exec(
            sprintf('timeout %d php %s 2>&1', $timeout, escapeshellarg($tmpFile))
        );

        @unlink($tmpFile);

        if ($result['code'] === 124) {
            throw new \RuntimeException("Job exceeded timeout of {$timeout} seconds and was killed");
        }

        if ($result['code'] !== 0) {
            throw new \RuntimeException($result['output'] ?: "Process exited with code {$result['code']}");
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

    private function createJobScript(KQueueJobInterface $job): string
    {
        /** @var LaravelJobAdapter $job */
        $tmpFile = tempnam(sys_get_temp_dir(), 'kqueue_laravel_job_');
        chmod($tmpFile, 0600);

        $laravelJob       = $job->getLaravelJob();
        $payload          = $laravelJob->payload();
        $serializedCommand = $payload['data']['command'] ?? null;

        if (!$serializedCommand) {
            throw new \RuntimeException('No command found in job payload');
        }

        $jobData      = base64_encode($serializedCommand);
        $memoryLimit  = min($job->getMaxMemory(), $this->maxMemory);
        $basePath     = base_path();

        $script = <<<PHP
<?php
ini_set('memory_limit', '{$memoryLimit}M');
require_once '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    \$command = unserialize(base64_decode('{$jobData}'));
    \$app->call([\$command, 'handle']);
    exit(0);
} catch (\Throwable \$e) {
    fwrite(STDERR, "Job failed: " . \$e->getMessage() . "\\n");
    exit(1);
}
PHP;

        file_put_contents($tmpFile, $script);

        return $tmpFile;
    }
}
