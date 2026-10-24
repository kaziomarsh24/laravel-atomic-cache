<?php

namespace KaziOmar\AtomicCache;

use Illuminate\Support\ServiceProvider;

class AtomicCacheServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/atomic-cache.php',
            'atomic-cache'
        );

        $this->app->singleton('atomic-cache', function ($app) {
            return new AtomicCache();
        });

        $this->app->alias('atomic-cache', AtomicCache::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/atomic-cache.php' => config_path('atomic-cache.php'),
            ], 'atomic-cache-config');
        }
    }
}
