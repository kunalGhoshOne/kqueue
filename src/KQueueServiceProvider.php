<?php

namespace KQueue;

use Illuminate\Support\ServiceProvider;
use KQueue\Console\KQueueWorkCommand;
use KQueue\Runtime\KQueueRuntime;
use KQueue\Runtime\SecureKQueueRuntime;
use KQueue\Runtime\SmartKQueueRuntime;
use KQueue\Analysis\JobAnalyzer;
use KQueue\Execution\SmartExecutionStrategySelector;

class KQueueServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge package configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/kqueue.php',
            'kqueue'
        );

        // Register KQueue runtime as singleton
        $this->app->singleton('kqueue.runtime', function ($app) {
            $config = $app['config']['kqueue'];

            // Determine runtime mode
            $useSmart = $config['runtime']['smart'] ?? true;
            $useSecure = $config['runtime']['secure'] ?? true;

            // Smart runtime with automatic strategy selection
            if ($useSmart) {
                $analyzer = new JobAnalyzer(
                    inlineThreshold: $config['analysis']['inline_threshold'] ?? 1.0,
                    pooledThreshold: $config['analysis']['pooled_threshold'] ?? 30.0
                );

                return new SmartKQueueRuntime(
                    loop: null,
                    strategySelector: null,
                    analyzer: $analyzer,
                    memoryLimitMB: $config['runtime']['memory_limit'] ?? 512
                );
            }

            // Secure runtime (original)
            if ($useSecure) {
                return new SecureKQueueRuntime(
                    null,
                    $config['runtime']['memory_limit'] ?? 512,
                    $config['jobs']['max_timeout'] ?? 300,
                    $config['jobs']['max_memory'] ?? 512,
                    $config['jobs']['max_concurrent'] ?? 100
                );
            }

            // Basic runtime
            return new KQueueRuntime(
                memoryLimitMB: $config['runtime']['memory_limit'] ?? 512
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__ . '/../config/kqueue.php' => config_path('kqueue.php'),
        ], 'kqueue-config');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                KQueueWorkCommand::class,
            ]);
        }
    }
}
