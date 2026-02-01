<?php

namespace KQueue;

use Illuminate\Support\ServiceProvider;

class KQueueServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register KQueue runtime as singleton
        $this->app->singleton('kqueue.runtime', function ($app) {
            return new Runtime\KQueueRuntime();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Future: Register console commands, publish configs, etc.
    }
}
