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
                BenchmarkCommand::class,
                ClearAtomicCacheCommand::class,
                CheckAtomicCacheCommand::class,
            ]);
        }

        $this->registerBladeDirectives();
        $this->registerQueryMacros();

        if (method_exists($this->app, 'terminating')) {
            $this->app->terminating(function () {
                AtomicCacheBladeHelper::reset();
            });
        }
    }

    /**
     * Register clean Blade @atomicCache and @endAtomicCache directives.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::directive('atomicCache', function ($expression) {
            return "<?php if (\\KaziOmar\\AtomicCache\\Blade\\AtomicCacheBladeHelper::setUp({$expression})) : ?>";
        });

        Blade::directive('endAtomicCache', function () {
            return "<?php echo \\KaziOmar\\AtomicCache\\Blade\\AtomicCacheBladeHelper::tearDown(); endif; ?>";
        });
    }

    /**
     * Register zero-key query macros for Eloquent and Query Builder.
     */
    protected function registerQueryMacros(): void
    {
        $fingerprintEagerLoads = function ($builder): string {
            if (!($builder instanceof \Illuminate\Database\Eloquent\Builder) || !method_exists($builder, 'getEagerLoads')) {
                return '';
            }

            $eagerLoads = $builder->getEagerLoads();
            if (empty($eagerLoads)) {
                return '';
            }

            $parts = [];
            foreach ($eagerLoads as $relation => $constraint) {
                $part = $relation;
                if ($constraint instanceof \Closure) {
                    try {
                        $ref = new \ReflectionFunction($constraint);
                        $staticVars = $ref->getStaticVariables();
                        $varsSerialized = '';
                        try {
                            $varsSerialized = serialize($staticVars);
                        } catch (\Throwable) {
                            $varsSerialized = json_encode(array_keys($staticVars));
                        }
                        $part .= ':' . md5($ref->getFileName() . ':' . $ref->getStartLine() . ':' . $ref->getEndLine() . ':' . $varsSerialized);
                    } catch (\Throwable) {
                        $part .= ':constrained:' . spl_object_hash($constraint);
                    }
                }
                $parts[] = $part;
            }
            sort($parts);
            return implode(';', $parts);
        };

        $macro = function (mixed $keyOrTtl = null, ?int $ttl = null, array $tags = [], bool $lock = false, string|array|null $scope = null) use ($fingerprintEagerLoads) {
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
                $eagerStr = $fingerprintEagerLoads($this);
                $fingerprint = hash('sha256', $sql . ':' . serialize($bindings) . ':' . ($modelClass ?? '') . ':' . $eagerStr);
                $cacheKey = "{$table}:query:{$fingerprint}";
            }

            if (!empty($scope)) {
                $scopeTags = is_array($scope) ? $scope : [$scope];
                $allTags = array_merge($scopeTags, $tags);
            } else {
                $autoTags = [$table];
                if ($modelClass) {
                    $autoTags[] = $modelClass;
                }

                // Also auto-tag any joined tables for joined query invalidation
                $joins = $isEloquent ? ($this->getQuery()->joins ?? []) : ($this->joins ?? []);
                if (is_array($joins)) {
                    foreach ($joins as $join) {
                        if (isset($join->table) && is_string($join->table)) {
                            $joinTable = preg_split('/\s+as\s+|\s+/i', trim($join->table))[0];
                            $autoTags[] = $joinTable;
                        }
                    }
                }
                $allTags = array_merge($autoTags, $tags);
            }

            return \KaziOmar\AtomicCache\Facades\AtomicCache::tags($allTags)->remember(
                $cacheKey,
                $effectiveTtl,
                fn() => $this->get(),
                lock: $lock
            );
        };

        \Illuminate\Database\Eloquent\Builder::macro('atomicCache', $macro);
        \Illuminate\Database\Query\Builder::macro('atomicCache', $macro);

        $firstMacro = function (mixed $keyOrTtl = null, ?int $ttl = null, array $tags = [], bool $lock = false, string|array|null $scope = null) use ($fingerprintEagerLoads) {
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
                $eagerStr = $fingerprintEagerLoads($this);
                $fingerprint = hash('sha256', $sql . ':' . serialize($bindings) . ':' . ($modelClass ?? '') . ':' . $eagerStr);
                $cacheKey = "{$table}:query:first:{$fingerprint}";
            }

            if (!empty($scope)) {
                $scopeTags = is_array($scope) ? $scope : [$scope];
                $allTags = array_merge($scopeTags, $tags);
            } else {
                $autoTags = [$table];
                if ($modelClass) {
                    $autoTags[] = $modelClass;
                }

                // Also auto-tag any joined tables for joined query invalidation
                $joins = $isEloquent ? ($this->getQuery()->joins ?? []) : ($this->joins ?? []);
                if (is_array($joins)) {
                    foreach ($joins as $join) {
                        if (isset($join->table) && is_string($join->table)) {
                            $joinTable = preg_split('/\s+as\s+|\s+/i', trim($join->table))[0];
                            $autoTags[] = $joinTable;
                        }
                    }
                }
                $allTags = array_merge($autoTags, $tags);
            }

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
}
