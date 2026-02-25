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
 * Executes jobs in isolated child processes (CONCURRENT - DEFAULT)
 *
 * This is the DEFAULT strategy - the main reason KQueue exists!
 * Jobs run concurrently in separate processes for true parallelism.
 *
 * To opt-out, set: public bool $isolated = false;
 *
 * Best for: CPU-intensive work, video processing, unsafe code, heavy jobs
 */
class IsolatedExecutionStrategy implements ExecutionStrategy
{
    private LoopInterface $loop;

    public function __construct(?LoopInterface $loop = null)
    {
        $this->loop = $loop ?? Loop::get();
    }

    public function canHandle(KQueueJobInterface $job): bool
    {
        // Handle all jobs by default (unless explicitly set to false)
        return $job->isIsolated() !== false;
    }

    public function execute(KQueueJobInterface $job): PromiseInterface
    {
        $deferred = new Deferred();

        // Create a temporary PHP file to execute the job
        $tmpFile = tempnam(sys_get_temp_dir(), 'kqueue_job_');
        $jobClass = get_class($job);
        $jobData = base64_encode(serialize($job));

        // Find autoloader path
        $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';

        // Get the file where the job class is defined
        $reflection = new \ReflectionClass($jobClass);
        $jobClassFile = $reflection->getFileName();

        // Create executable script
        $script = <<<PHP
<?php
require_once '{$autoloadPath}';
require_once '{$jobClassFile}';
\$job = unserialize(base64_decode('{$jobData}'));
try {
    \$job->handle();
    exit(0);
} catch (\Throwable \$e) {
    fwrite(STDERR, \$e->getMessage() . "\\n" . \$e->getTraceAsString());
    exit(1);
}
PHP;

        file_put_contents($tmpFile, $script);

        $process = new Process('php ' . escapeshellarg($tmpFile));
        $process->start($this->loop);

        $stderr = '';
        $stdout = '';

        $process->stdout->on('data', function($data) use (&$stdout) {
            $stdout .= $data;
            echo $data; // Forward stdout
        });

        $process->stderr->on('data', function($data) use (&$stderr) {
            $stderr .= $data;
        });

        $process->on('exit', function($exitCode) use ($deferred, &$stderr, $tmpFile) {
            @unlink($tmpFile); // Clean up temp file

            if ($exitCode === 0) {
                $deferred->resolve(null);
            } else {
                $error = $stderr ?: 'Process exited with code ' . $exitCode;
                $deferred->reject(new \RuntimeException($error));
            }
        });

        return $deferred->promise();
    }
}
