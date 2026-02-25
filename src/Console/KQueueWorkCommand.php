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

class KQueueWorkCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kqueue:work
                            {connection? : The name of the queue connection to work}
                            {--queue=default : The name of the queue to work}
                            {--sleep=100 : Number of milliseconds to sleep between polls}
                            {--timeout=60 : The number of seconds a job can run before timing out}
                            {--memory=512 : The memory limit in megabytes for the runtime}
                            {--max-jobs=0 : The number of jobs to process before stopping (0 = unlimited)}
                            {--max-time=0 : The maximum number of seconds to run (0 = unlimited)}
                            {--inline : Force all jobs to run inline/sequential (opt-out of concurrency)}
                            {--smart : Use smart runtime with automatic job analysis (RECOMMENDED)}
                            {--secure : Use secure runtime with hardened security features}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start processing jobs from a Laravel queue using KQueue runtime';

    /**
     * Execute the console command.
     */
    public function handle(QueueManager $queueManager, Dispatcher $events): int
    {
        try {
            // Get connection name (from argument, config, or default)
            $connection = $this->argument('connection')
                ?? config('kqueue.default_connection')
                ?? config('queue.default');

            // Validate connection exists
            if (!$this->validateConnection($connection)) {
                $this->error("Queue connection [{$connection}] is not configured.");
                return 1;
            }

            // Display startup information
            $this->displayStartupInfo($connection);

            // Create runtime
            $runtime = $this->createRuntime();

            // Register execution strategies
            $this->registerStrategies($runtime);

            // Create worker
            $worker = $this->createWorker($runtime, $queueManager, $events);

            // Register global error handler
            $this->registerErrorHandler();

            // Start worker
            $worker->work();

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to start worker: ' . $e->getMessage());
            $this->line('');
            $this->line($e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Validate queue connection configuration
     */
    private function validateConnection(string $connection): bool
    {
        $connections = config('queue.connections', []);
        return isset($connections[$connection]);
    }

    /**
     * Display startup information
     */
    private function displayStartupInfo(string $connection): void
    {
        $this->line('');
        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║          KQueue Worker Starting                         ║');
        $this->info('╚════════════════════════════════════════════════════════╝');
        $this->line('');

        $this->line(sprintf('  <comment>Connection:</comment>  %s', $connection));
        $this->line(sprintf('  <comment>Queue:</comment>       %s', $this->option('queue')));
        $this->line(sprintf('  <comment>Sleep:</comment>       %dms', $this->option('sleep')));
        $this->line(sprintf('  <comment>Timeout:</comment>     %ds', $this->option('timeout')));
        $this->line(sprintf('  <comment>Memory:</comment>      %dMB', $this->option('memory')));

        if ($this->option('max-jobs') > 0) {
            $this->line(sprintf('  <comment>Max Jobs:</comment>    %d', $this->option('max-jobs')));
        }

        if ($this->option('max-time') > 0) {
            $this->line(sprintf('  <comment>Max Time:</comment>    %ds', $this->option('max-time')));
        }

        $useSmart = $this->option('smart') || config('kqueue.runtime.smart', true);
        $runtime = $useSmart ? 'Smart (Auto-detect)' : ($this->option('secure') ? 'Secure' : 'Standard');
        $this->line(sprintf('  <comment>Runtime:</comment>     %s', $runtime));

        if ($useSmart) {
            $this->line('  <comment>Mode:</comment>        <fg=cyan>🧠 Smart Analysis Enabled (Auto-select strategy)</>');
        } elseif ($this->option('inline')) {
            $this->line('  <comment>Mode:</comment>        <fg=yellow>Sequential (inline) - concurrency disabled</>');
        } else {
            $this->line('  <comment>Mode:</comment>        <fg=green>Concurrent (isolated by default)</>');
        }

        $this->line('');
        $this->info('Press Ctrl+C to stop worker gracefully');
        $this->line('');
    }

    /**
     * Create KQueue runtime
     */
    private function createRuntime(): KQueueRuntime|SecureKQueueRuntime|SmartKQueueRuntime
    {
        $memoryLimit = (int) $this->option('memory');
        $useSmart = $this->option('smart') || config('kqueue.runtime.smart', true);
        $useSecure = $this->option('secure') || config('kqueue.runtime.secure', true);

        // Smart runtime with automatic strategy selection (BEST OPTION!)
        if ($useSmart) {
            $analyzer = new JobAnalyzer(
                inlineThreshold: config('kqueue.analysis.inline_threshold', 1.0),
                pooledThreshold: config('kqueue.analysis.pooled_threshold', 30.0)
            );

            return new SmartKQueueRuntime(
                loop: null,
                strategySelector: null,
                analyzer: $analyzer,
                memoryLimitMB: $memoryLimit
            );
        }

        // Secure runtime (manual strategy selection)
        if ($useSecure) {
            $maxTimeout = config('kqueue.jobs.max_timeout', 300);
            $maxMemory = config('kqueue.jobs.max_memory', 512);
            $maxConcurrent = config('kqueue.jobs.max_concurrent', 100);

            return new SecureKQueueRuntime(
                null,
                $memoryLimit,
                $maxTimeout,
                $maxMemory,
                $maxConcurrent
            );
        }

        // Basic runtime (manual strategy selection)
        return new KQueueRuntime(memoryLimitMB: $memoryLimit);
    }

    /**
     * Register execution strategies
     */
    private function registerStrategies(KQueueRuntime|SecureKQueueRuntime|SmartKQueueRuntime $runtime): void
    {
        $useSmart = $this->option('smart') || config('kqueue.runtime.smart', true);

        // Smart runtime uses strategy selector
        if ($useSmart && $runtime instanceof SmartKQueueRuntime) {
            $selector = $runtime->getStrategySelector();
            $loop = $runtime->getLoop();
            $maxMemory = config('kqueue.jobs.max_memory', 512);
            $maxTimeout = config('kqueue.jobs.max_timeout', 300);

            // Register strategies for each execution mode
            $selector->registerStrategy('inline', new SecureInlineExecutionStrategy($maxMemory));
            $selector->registerStrategy('pooled', new IsolatedExecutionStrategy($loop)); // TODO: Add PooledExecutionStrategy
            $selector->registerStrategy('isolated', new SecureLaravelIsolatedExecutionStrategy(
                $loop,
                $maxTimeout,
                $maxMemory
            ));

            $this->line('<info>✓</info> Smart execution strategies registered (inline, pooled, isolated)');
            return;
        }

        // Manual runtime (legacy)
        $useSecure = $this->option('secure') || config('kqueue.runtime.secure', true);
        $loop = $runtime->getLoop();

        if ($useSecure) {
            // Secure strategies
            $maxTimeout = config('kqueue.jobs.max_timeout', 300);
            $maxMemory = config('kqueue.jobs.max_memory', 512);

            // Register Laravel-specific isolated strategy FIRST (most specific)
            $runtime->addStrategy(new SecureLaravelIsolatedExecutionStrategy(
                $loop,
                $maxTimeout,
                $maxMemory
            ));

            // Then register generic isolated strategy (for non-Laravel jobs)
            $allowedPaths = config('kqueue.security.allowed_job_paths', []);
            $runtime->addStrategy(new SecureIsolatedExecutionStrategy(
                $loop,
                $allowedPaths,
                $maxTimeout,
                $maxMemory
            ));

            // Finally register inline strategy (opt-in for lightweight jobs)
            $runtime->addStrategy(new SecureInlineExecutionStrategy($maxMemory));
        } else {
            // Standard strategies
            $runtime->addStrategy(new IsolatedExecutionStrategy($loop));
            $runtime->addStrategy(new InlineExecutionStrategy());
        }

        $this->line('<info>✓</info> Execution strategies registered');
    }

    /**
     * Create Laravel queue worker
     */
    private function createWorker(
        KQueueRuntime|SecureKQueueRuntime|SmartKQueueRuntime $runtime,
        QueueManager $queueManager,
        Dispatcher $events
    ): LaravelQueueWorker {
        $connection = $this->argument('connection')
            ?? config('kqueue.default_connection')
            ?? config('queue.default');

        $options = [
            'connection' => $connection,
            'queue' => $this->option('queue'),
            'sleep' => (int) $this->option('sleep'),
            'maxJobs' => (int) $this->option('max-jobs'),
            'maxTime' => (int) $this->option('max-time'),
            'defaultTimeout' => (int) $this->option('timeout'),
            'defaultMemory' => config('kqueue.jobs.default_memory', 128),
            'defaultIsolated' => $this->option('inline')
                ? false  // --inline flag forces sequential execution
                : config('kqueue.jobs.isolated_by_default', true),  // Default to concurrent!
        ];

        return new LaravelQueueWorker($runtime, $queueManager, $events, $options);
    }

    /**
     * Register global error handler
     */
    private function registerErrorHandler(): void
    {
        set_exception_handler(function (\Throwable $e) {
            $this->error('Uncaught exception: ' . $e->getMessage());
            $this->line($e->getTraceAsString());

            // Exit with error code
            exit(1);
        });
    }
}
