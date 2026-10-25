<?php

namespace KaziOmar\AtomicCache;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use KaziOmar\AtomicCache\Blade\AtomicCacheBladeHelper;
use KaziOmar\AtomicCache\Commands\BenchmarkCommand;
use KaziOmar\AtomicCache\Commands\CheckAtomicCacheCommand;
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
                CheckAtomicCacheCommand::class,
            ]);
        }

        $this->registerBladeDirectives();
        $this->registerQueryMacros();
    }

    /**
     * Register Eloquent and Database query macros for zero-key fluent query caching.
     */
    protected function registerQueryMacros(): void
    {
        $macro = function (mixed $keyOrTtl = null, ?int $ttl = null, array $tags = [], bool $lock = false) {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $isEloquent = $this instanceof \Illuminate\Database\Eloquent\Builder;

            $rawTable = $isEloquent ? $this->getModel()->getTable() : ($this->from ?? 'db');
            $table = is_string($rawTable) ? preg_split('/\s+as\s+|\s+/i', trim($rawTable))[0] : 'db';
            $modelClass = $isEloquent ? get_class($this->getModel()) : null;

            $customKey = null;
            $effectiveTtl = null;

            if (is_string($keyOrTtl)) {
                $customKey = $keyOrTtl;
                $effectiveTtl = $ttl;
            } elseif (is_numeric($keyOrTtl)) {
                $effectiveTtl = (int) $keyOrTtl;
            }

            if ($customKey !== null) {
                $cacheKey = "{$table}:{$customKey}";
            } else {
                $sql = $this->toSql();
                $bindings = $this->getBindings();
                $fingerprint = md5($sql . ':' . serialize($bindings));
                $cacheKey = "{$table}:query:{$fingerprint}";
            }

            $autoTags = [$table];
            if ($modelClass) {
                $autoTags[] = $modelClass;
            }
            $allTags = array_merge($autoTags, $tags);

            return \KaziOmar\AtomicCache\Facades\AtomicCache::tags($allTags)->remember(
                $cacheKey,
                $effectiveTtl,
                fn() => $this->get(),
                lock: $lock
            );
        };

        \Illuminate\Database\Eloquent\Builder::macro('atomicCache', $macro);
        \Illuminate\Database\Query\Builder::macro('atomicCache', $macro);

        $firstMacro = function (mixed $keyOrTtl = null, ?int $ttl = null, array $tags = [], bool $lock = false) {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $isEloquent = $this instanceof \Illuminate\Database\Eloquent\Builder;

            $rawTable = $isEloquent ? $this->getModel()->getTable() : ($this->from ?? 'db');
            $table = is_string($rawTable) ? preg_split('/\s+as\s+|\s+/i', trim($rawTable))[0] : 'db';
            $modelClass = $isEloquent ? get_class($this->getModel()) : null;

            $customKey = null;
            $effectiveTtl = null;

            if (is_string($keyOrTtl)) {
                $customKey = $keyOrTtl;
                $effectiveTtl = $ttl;
            } elseif (is_numeric($keyOrTtl)) {
                $effectiveTtl = (int) $keyOrTtl;
            }

            if ($customKey !== null) {
                $cacheKey = "{$table}:{$customKey}:first";
            } else {
                $sql = $this->toSql();
                $bindings = $this->getBindings();
                $fingerprint = md5($sql . ':' . serialize($bindings));
                $cacheKey = "{$table}:query:first:{$fingerprint}";
            }

            $autoTags = [$table];
            if ($modelClass) {
                $autoTags[] = $modelClass;
            }
            $allTags = array_merge($autoTags, $tags);

            return \KaziOmar\AtomicCache\Facades\AtomicCache::tags($allTags)->remember(
                $cacheKey,
                $effectiveTtl,
                fn() => $this->first(),
                lock: $lock
            );
        };

        \Illuminate\Database\Eloquent\Builder::macro('atomicCacheFirst', $firstMacro);
        \Illuminate\Database\Query\Builder::macro('atomicCacheFirst', $firstMacro);
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
