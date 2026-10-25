<?php

namespace KaziOmar\AtomicCache;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use KaziOmar\AtomicCache\Blade\AtomicCacheBladeHelper;
use KaziOmar\AtomicCache\Commands\BenchmarkCommand;
use KaziOmar\AtomicCache\Commands\ClearAtomicCacheCommand;

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

            $this->commands([
                ClearAtomicCacheCommand::class,
                BenchmarkCommand::class,
            ]);
        }

        $this->registerBladeDirectives();
    }

    /**
     * Register Blade directives for template fragment caching.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::directive('atomicCache', function ($expression) {
            return "<?php if (\\KaziOmar\\AtomicCache\\Blade\\AtomicCacheBladeHelper::setUp({$expression})): ?>";
        });

        Blade::directive('endAtomicCache', function () {
            return "<?php echo \\KaziOmar\\AtomicCache\\Blade\\AtomicCacheBladeHelper::tearDown(); endif; ?>";
        });
    }
}
