<?php

namespace KQueue;

use Illuminate\Support\ServiceProvider;
use KQueue\Console\KQueueWorkCommand;
use KQueue\Runtime\KQueueRuntime;
use KQueue\Runtime\SecureKQueueRuntime;
use KQueue\Runtime\SmartKQueueRuntime;
use KQueue\Analysis\JobAnalyzer;
use KQueue\Execution\SmartExecutionStrategySelector;
use KQueue\Swoole\SwooleStateManager;

class KQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/kqueue.php', 'kqueue');

        $this->app->singleton(SwooleStateManager::class, function ($app) {
            $resettable = $app['config']['kqueue']['swoole']['resettable_singletons'] ?? [];
            return new SwooleStateManager($resettable);
        });

        $this->app->singleton('kqueue.runtime', function ($app) {
            $config       = $app['config']['kqueue'];
            $useSmart     = $config['runtime']['smart']  ?? true;
            $useSecure    = $config['runtime']['secure'] ?? true;
            $memoryLimit  = $config['runtime']['memory_limit'] ?? 512;
            $stateManager = $app->make(SwooleStateManager::class);

            if ($useSmart) {
                $analyzer = new JobAnalyzer(
                    inlineThreshold: $config['analysis']['inline_threshold'] ?? 1.0,
                    pooledThreshold: $config['analysis']['pooled_threshold'] ?? 30.0
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
                    maxJobTimeout: $config['jobs']['max_timeout'] ?? 300,
                    maxJobMemory: $config['jobs']['max_memory']   ?? 512,
                    maxConcurrentJobs: $config['jobs']['max_concurrent'] ?? 100,
                    stateManager: $stateManager
                );
            }

            return new KQueueRuntime(
                memoryLimitMB: $memoryLimit,
                stateManager: $stateManager
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/kqueue.php' => config_path('kqueue.php'),
        ], 'kqueue-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                KQueueWorkCommand::class,
            ]);
        }
    }
}
