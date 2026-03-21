<?php

namespace KQueue\Execution;

use KQueue\Contracts\ExecutionStrategy;
use KQueue\Contracts\KQueueJobInterface;

/**
 * Runs the job in a separate child process.
 *
 * Uses Swoole\Coroutine\System::exec() so the calling coroutine suspends
 * while waiting — other jobs keep running concurrently on the same thread.
 * The child process is killed automatically if it exceeds the job timeout.
 *
 * Best for: CPU-intensive jobs (image/video processing, heavy computation,
 * jobs that use non-hookable extensions like old mongo driver).
 *
 * Default strategy when $isolated is null or true.
 */
class IsolatedExecutionStrategy implements ExecutionStrategy
{
    public function canHandle(KQueueJobInterface $job): bool
    {
        return $job->isIsolated() !== false;
    }

    public function execute(KQueueJobInterface $job): void
    {
        $tmpFile = $this->createJobScript($job);
        $timeout = $job->getTimeout();

        // Coroutine suspends here — non-blocking wait for child process to finish.
        // 'timeout' command kills the process if it exceeds $timeout seconds.
        $result = \Swoole\Coroutine\System::exec(
            sprintf('timeout %d php %s 2>&1', $timeout, escapeshellarg($tmpFile))
        );

        @unlink($tmpFile);

        if ($result['code'] === 124) {
            throw new \RuntimeException("Job timed out after {$timeout} seconds");
        }

        if ($result['code'] !== 0) {
            throw new \RuntimeException($result['output'] ?: "Process exited with code {$result['code']}");
        }
    }

    private function createJobScript(KQueueJobInterface $job): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'kqueue_job_');
        chmod($tmpFile, 0600);

        $jobClass  = get_class($job);
        $jobData   = base64_encode(serialize($job));
        $reflection    = new \ReflectionClass($jobClass);
        $jobClassFile  = $reflection->getFileName();
        $autoloadPath  = $this->findAutoloadPath();

        $script = <<<PHP
<?php
require_once '{$autoloadPath}';
require_once '{$jobClassFile}';
\$job = unserialize(base64_decode('{$jobData}'));
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

    private function findAutoloadPath(): string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            dirname(__DIR__, 3) . '/autoload.php',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new \RuntimeException('Could not locate vendor/autoload.php');
    }
}
