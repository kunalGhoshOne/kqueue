<?php

namespace KQueue\Console;

use Illuminate\Console\Command;
use Illuminate\Queue\QueueManager;
use Illuminate\Contracts\Events\Dispatcher;
use KQueue\Runtime\KQueueRuntime;
use KQueue\Runtime\SecureKQueueRuntime;
use KQueue\Runtime\SmartKQueueRuntime;
use KQueue\Execution\InlineExecutionStrategy;
use KQueue\Execution\IsolatedExecutionStrategy;
use KQueue\Execution\SecureInlineExecutionStrategy;
use KQueue\Execution\SecureIsolatedExecutionStrategy;
use KQueue\Execution\SecureLaravelIsolatedExecutionStrategy;
use KQueue\Analysis\JobAnalyzer;
use KQueue\Queue\LaravelQueueWorker;
use KQueue\Swoole\SwooleStateManager;

class KQueueWorkCommand extends Command
{
    protected $signature = 'kqueue:work
                            {connection? : The name of the queue connection to work}
                            {--queue=default : The name of the queue to work}
                            {--sleep=100 : Milliseconds between queue polls}
                            {--timeout=60 : Seconds a job can run before timing out}
                            {--memory=512 : Runtime memory limit in megabytes}
                            {--max-jobs=0 : Jobs to process before stopping (0 = unlimited)}
                            {--max-time=0 : Seconds to run before stopping (0 = unlimited)}
                            {--inline : Force all jobs to run inline (disables process isolation)}
                            {--smart : Use smart runtime with automatic job analysis (default)}
                            {--secure : Use secure runtime with hardened security features}';

    protected $description = 'Start the KQueue worker — concurrent, non-blocking job processing powered by Swoole';

    public function handle(QueueManager $queueManager, Dispatcher $events): int
    {
        // Check 1: Swoole must be installed
        if (!extension_loaded('swoole')) {
            $this->error('Swoole extension is not installed.');
            $this->line('');
            $this->line('Install it with one of:');
            $this->line('  Ubuntu/Debian:  <comment>apt install php-swoole</comment>');
            $this->line('  PECL:           <comment>pecl install swoole</comment>');
            $this->line('  Docker:         <comment>RUN pecl install swoole && docker-php-ext-enable swoole</comment>');
            $this->line('');

            return 1;
        }

        // Check 2: Warn about non-hookable extensions
        // Jobs using these are automatically routed to isolated processes
        $nonHookable = SwooleStateManager::detectNonHookableExtensions();
        if (!empty($nonHookable)) {
            $this->warn('Non-hookable extensions detected: ' . implode(', ', $nonHookable));
            $this->line('  Jobs using these extensions will be auto-routed to isolated processes.');
            $this->line('');
        }

        try {
            $connection = $this->argument('connection')
                ?? config('kqueue.default_connection')
                ?? config('queue.default');

            if (!$this->validateConnection($connection)) {
                $this->error("Queue connection [{$connection}] is not configured in config/queue.php");
                return 1;
            }

            $this->displayStartupInfo($connection);

            $stateManager = $this->buildStateManager();
            $runtime      = $this->createRuntime($stateManager);

            $this->registerStrategies($runtime);

            $worker = $this->createWorker($runtime, $queueManager, $events, $connection);

            set_exception_handler(function (\Throwable $e) {
                $this->error('Uncaught exception: ' . $e->getMessage());
                exit(1);
            });

            $worker->work();

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to start worker: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        }
    }

    private function buildStateManager(): SwooleStateManager
    {
        $resettable = config('kqueue.swoole.resettable_singletons', []);

        return new SwooleStateManager($resettable);
    }

    private function validateConnection(string $connection): bool
    {
        return isset(config('queue.connections', [])[$connection]);
    }

    private function displayStartupInfo(string $connection): void
    {
        $this->line('');
        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║           KQueue Worker  —  Powered by Swoole          ║');
        $this->info('╚════════════════════════════════════════════════════════╝');
        $this->line('');
        $this->line(sprintf('  <comment>Connection:</comment>  %s', $connection));
        $this->line(sprintf('  <comment>Queue:</comment>       %s', $this->option('queue')));
        $this->line(sprintf('  <comment>Sleep:</comment>       %dms', $this->option('sleep')));
        $this->line(sprintf('  <comment>Timeout:</comment>     %ds', $this->option('timeout')));
        $this->line(sprintf('  <comment>Memory:</comment>      %dMB', $this->option('memory')));

        if ((int) $this->option('max-jobs') > 0) {
            $this->line(sprintf('  <comment>Max Jobs:</comment>    %d', $this->option('max-jobs')));
        }
        if ((int) $this->option('max-time') > 0) {
            $this->line(sprintf('  <comment>Max Time:</comment>    %ds', $this->option('max-time')));
        }

        $useSmart = $this->option('smart') || config('kqueue.runtime.smart', true);
        $runtime  = $useSmart ? 'Smart (auto-detect)' : ($this->option('secure') ? 'Secure' : 'Standard');
        $this->line(sprintf('  <comment>Runtime:</comment>     %s', $runtime));

        if ($this->option('inline')) {
            $this->line('  <comment>Mode:</comment>        Sequential (inline) — process isolation disabled');
        } else {
            $this->line('  <comment>Mode:</comment>        Concurrent — jobs run as Swoole coroutines');
        }

        $this->line('');
        $this->line('  <info>SWOOLE_HOOK_ALL enabled</info> — sleep(), DB, HTTP, file I/O are non-blocking');
        $this->line('  <info>State isolation enabled</info> — globals and singletons reset per job');
        $this->line('');
        $this->line('Press Ctrl+C to stop gracefully');
        $this->line('');
    }

    private function createRuntime(SwooleStateManager $stateManager): KQueueRuntime|SecureKQueueRuntime|SmartKQueueRuntime
    {
        $memoryLimit = (int) $this->option('memory');
        $useSmart    = $this->option('smart') || config('kqueue.runtime.smart', true);
        $useSecure   = $this->option('secure') || config('kqueue.runtime.secure', true);

        if ($useSmart) {
            $analyzer = new JobAnalyzer(
                inlineThreshold: config('kqueue.analysis.inline_threshold', 1.0),
                pooledThreshold: config('kqueue.analysis.pooled_threshold', 30.0)
            );

            return new SmartKQueueRuntime(
                strategySelector: null,
                analyzer: $analyzer,
                memoryLimitMB: $memoryLimit,
                stateManager: $stateManager
            );
        }

        if ($useSecure) {
            return new SecureKQueueRuntime(
                memoryLimitMB: $memoryLimit,
                maxJobTimeout: config('kqueue.jobs.max_timeout', 300),
                maxJobMemory: config('kqueue.jobs.max_memory', 512),
                maxConcurrentJobs: config('kqueue.jobs.max_concurrent', 100),
                stateManager: $stateManager
            );
        }

        return new KQueueRuntime(
            memoryLimitMB: $memoryLimit,
            stateManager: $stateManager
        );
    }

    private function registerStrategies(KQueueRuntime|SecureKQueueRuntime|SmartKQueueRuntime $runtime): void
    {
        $useSmart  = $this->option('smart') || config('kqueue.runtime.smart', true);
        $useSecure = $this->option('secure') || config('kqueue.runtime.secure', true);

        if ($useSmart && $runtime instanceof SmartKQueueRuntime) {
            $selector    = $runtime->getStrategySelector();
            $maxMemory   = config('kqueue.jobs.max_memory', 512);
            $maxTimeout  = config('kqueue.jobs.max_timeout', 300);

            $selector->registerStrategy('inline', new SecureInlineExecutionStrategy($maxMemory));
            $selector->registerStrategy('pooled', new SecureLaravelIsolatedExecutionStrategy($maxTimeout, $maxMemory));
            $selector->registerStrategy('isolated', new SecureLaravelIsolatedExecutionStrategy($maxTimeout, $maxMemory));

            $this->line('<info>✓</info> Smart execution strategies registered (inline coroutine, pooled, isolated process)');
            return;
        }

        if ($useSecure) {
            $maxTimeout   = config('kqueue.jobs.max_timeout', 300);
            $maxMemory    = config('kqueue.jobs.max_memory', 512);
            $allowedPaths = config('kqueue.security.allowed_job_paths', []);

            // Laravel jobs — most specific, registered first
            $runtime->addStrategy(new SecureLaravelIsolatedExecutionStrategy($maxTimeout, $maxMemory));
            // Generic KQueue jobs
            $runtime->addStrategy(new SecureIsolatedExecutionStrategy($allowedPaths, $maxTimeout, $maxMemory));
            // Opt-in inline
            $runtime->addStrategy(new SecureInlineExecutionStrategy($maxMemory));
        } else {
            $runtime->addStrategy(new IsolatedExecutionStrategy());
            $runtime->addStrategy(new InlineExecutionStrategy());
        }

        $this->line('<info>✓</info> Execution strategies registered');
    }

    private function createWorker(
        KQueueRuntime|SecureKQueueRuntime|SmartKQueueRuntime $runtime,
        QueueManager $queueManager,
        Dispatcher $events,
        string $connection
    ): LaravelQueueWorker {
        return new LaravelQueueWorker($runtime, $queueManager, $events, [
            'connection'      => $connection,
            'queue'           => $this->option('queue'),
            'sleep'           => (int) $this->option('sleep'),
            'maxJobs'         => (int) $this->option('max-jobs'),
            'maxTime'         => (int) $this->option('max-time'),
            'defaultTimeout'  => (int) $this->option('timeout'),
            'defaultMemory'   => config('kqueue.jobs.default_memory', 128),
            'defaultIsolated' => $this->option('inline')
                ? false
                : config('kqueue.jobs.isolated_by_default', true),
        ]);
    }
}
