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

        $resolveQueryMeta = function ($builder) use ($fingerprintEagerLoads) {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder */
            $isEloquent = $builder instanceof \Illuminate\Database\Eloquent\Builder;

            $rawTable = $isEloquent ? $builder->getModel()->getTable() : ($builder->from ?? 'db');
            if (is_object($rawTable)) {
                if (method_exists($rawTable, 'getValue')) {
                    try {
                        $rawTable = (string) $rawTable->getValue(\Illuminate\Support\Facades\DB::connection()->getQueryGrammar());
                    } catch (\Throwable) {
                        $rawTable = 'db';
                    }
                } elseif (method_exists($rawTable, '__toString')) {
                    $rawTable = (string) $rawTable;
                } else {
                    $rawTable = 'db';
                }
            }
            $table = is_string($rawTable) ? preg_split('/\s+as\s+|\s+/i', trim($rawTable))[0] : 'db';
            $modelClass = $isEloquent ? get_class($builder->getModel()) : null;

            $sql = $builder->toSql();
            $bindings = $builder->getBindings();
            $eagerStr = $fingerprintEagerLoads($builder);
            $queryFingerprint = hash('sha256', $sql . ':' . serialize($bindings) . ':' . ($modelClass ?? '') . ':' . $eagerStr);

            $autoTags = [$table];
            if ($modelClass) {
                $autoTags[] = $modelClass;
            }

            // Also auto-tag any joined tables for joined query invalidation
            $joins = $isEloquent ? ($builder->getQuery()->joins ?? []) : ($builder->joins ?? []);
            if (is_array($joins)) {
                foreach ($joins as $join) {
                    if (isset($join->table) && is_string($join->table)) {
                        $joinTable = preg_split('/\s+as\s+|\s+/i', trim($join->table))[0];
                        $autoTags[] = $joinTable;
                    }
                }
            }

            return [$table, $modelClass, $queryFingerprint, array_values(array_unique($autoTags))];
        };

        $resolveTtlAndKey = function (mixed $keyOrTtl, mixed $ttl) {
            $customKey = null;
            $effectiveTtl = null;

            if (is_string($keyOrTtl)) {
                $customKey = $keyOrTtl;
                $effectiveTtl = \KaziOmar\AtomicCache\AtomicCache::parseTtlSeconds($ttl);
            } else {
                $effectiveTtl = \KaziOmar\AtomicCache\AtomicCache::parseTtlSeconds($keyOrTtl ?? $ttl);
            }

            return [$customKey, $effectiveTtl];
        };

        $macro = function (mixed $keyOrTtl = null, mixed $ttl = null, array $tags = [], bool $lock = false, string|array|null $scope = null) use ($resolveQueryMeta, $resolveTtlAndKey) {
            [$table, $modelClass, $fingerprint, $autoTags] = $resolveQueryMeta($this);
            [$customKey, $effectiveTtl] = $resolveTtlAndKey($keyOrTtl, $ttl);

            $cacheKey = $customKey !== null ? "{$table}:{$customKey}" : "{$table}:query:{$fingerprint}";
            $allTags = !empty($scope) ? array_merge(is_array($scope) ? $scope : [$scope], $tags) : array_merge($autoTags, $tags);

            return \KaziOmar\AtomicCache\Facades\AtomicCache::tags($allTags)->remember(
                $cacheKey,
                $effectiveTtl,
                fn() => $this->get(),
                lock: $lock
            );
        };

        \Illuminate\Database\Eloquent\Builder::macro('atomicCache', $macro);
        \Illuminate\Database\Query\Builder::macro('atomicCache', $macro);

        $firstMacro = function (mixed $keyOrTtl = null, mixed $ttl = null, array $tags = [], bool $lock = false, string|array|null $scope = null) use ($resolveQueryMeta, $resolveTtlAndKey) {
            [$table, $modelClass, $fingerprint, $autoTags] = $resolveQueryMeta($this);
            [$customKey, $effectiveTtl] = $resolveTtlAndKey($keyOrTtl, $ttl);

            $cacheKey = $customKey !== null ? "{$table}:{$customKey}:first" : "{$table}:query:first:{$fingerprint}";
            $allTags = !empty($scope) ? array_merge(is_array($scope) ? $scope : [$scope], $tags) : array_merge($autoTags, $tags);

            return \KaziOmar\AtomicCache\Facades\AtomicCache::tags($allTags)->remember(
                $cacheKey,
                $effectiveTtl,
                fn() => $this->first(),
                lock: $lock
            );
        };

        \Illuminate\Database\Eloquent\Builder::macro('atomicCacheFirst', $firstMacro);
        \Illuminate\Database\Query\Builder::macro('atomicCacheFirst', $firstMacro);

        $paginateMacro = function (
            mixed $perPage = null,
            mixed $columnsOrTtl = ['*'],
            string $pageName = 'page',
            ?int $page = null,
            mixed $keyOrTtl = null,
            mixed $ttl = null,
            array $tags = [],
            bool $lock = false,
            string|array|null $scope = null
        ) use ($resolveQueryMeta, $resolveTtlAndKey) {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $columns = ['*'];
            $passedTtl = null;

            if (is_array($columnsOrTtl)) {
                $columns = $columnsOrTtl;
            } elseif (is_numeric($columnsOrTtl) || $columnsOrTtl instanceof \DateTimeInterface || $columnsOrTtl instanceof \DateInterval) {
                $passedTtl = $columnsOrTtl;
            }

            [$table, $modelClass, $fingerprint, $autoTags] = $resolveQueryMeta($this);
            [$customKey, $effectiveTtl] = $resolveTtlAndKey($keyOrTtl, $ttl ?? $passedTtl);

            $resolvedPage = $page;
            if ($resolvedPage === null && function_exists('app') && app()->bound('request')) {
                try {
                    $resolvedPage = (int) request()->input($pageName, 1);
                } catch (\Throwable) {
                    $resolvedPage = 1;
                }
            }
            $resolvedPage = max(1, (int) ($resolvedPage ?? 1));

            $isEloquent = $this instanceof \Illuminate\Database\Eloquent\Builder;
            $effectivePerPage = (is_numeric($perPage) && (int) $perPage > 0)
                ? (int) $perPage
                : ($isEloquent ? $this->getModel()->getPerPage() : 15);

            $paginationFingerprint = "page:{$pageName}:{$resolvedPage}:per_page:{$effectivePerPage}:cols:" . implode(',', $columns);

            $cacheKey = $customKey !== null
                ? "{$table}:{$customKey}:paginate:{$paginationFingerprint}"
                : "{$table}:query:paginate:{$fingerprint}:{$paginationFingerprint}";

            $allTags = !empty($scope) ? array_merge(is_array($scope) ? $scope : [$scope], $tags) : array_merge($autoTags, $tags);

            return \KaziOmar\AtomicCache\Facades\AtomicCache::tags($allTags)->remember(
                $cacheKey,
                $effectiveTtl,
                fn() => $this->paginate($perPage, $columns, $pageName, $page),
                lock: $lock
            );
        };

        \Illuminate\Database\Eloquent\Builder::macro('atomicCachePaginate', $paginateMacro);
        \Illuminate\Database\Query\Builder::macro('atomicCachePaginate', $paginateMacro);

        $simplePaginateMacro = function (
            mixed $perPage = null,
            mixed $columnsOrTtl = ['*'],
            string $pageName = 'page',
            ?int $page = null,
            mixed $keyOrTtl = null,
            mixed $ttl = null,
            array $tags = [],
            bool $lock = false,
            string|array|null $scope = null
        ) use ($resolveQueryMeta, $resolveTtlAndKey) {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $columns = ['*'];
            $passedTtl = null;

            if (is_array($columnsOrTtl)) {
                $columns = $columnsOrTtl;
            } elseif (is_numeric($columnsOrTtl) || $columnsOrTtl instanceof \DateTimeInterface || $columnsOrTtl instanceof \DateInterval) {
                $passedTtl = $columnsOrTtl;
            }

            [$table, $modelClass, $fingerprint, $autoTags] = $resolveQueryMeta($this);
            [$customKey, $effectiveTtl] = $resolveTtlAndKey($keyOrTtl, $ttl ?? $passedTtl);

            $resolvedPage = $page;
            if ($resolvedPage === null && function_exists('app') && app()->bound('request')) {
                try {
                    $resolvedPage = (int) request()->input($pageName, 1);
                } catch (\Throwable) {
                    $resolvedPage = 1;
                }
            }
            $resolvedPage = max(1, (int) ($resolvedPage ?? 1));

            $isEloquent = $this instanceof \Illuminate\Database\Eloquent\Builder;
            $effectivePerPage = (is_numeric($perPage) && (int) $perPage > 0)
                ? (int) $perPage
                : ($isEloquent ? $this->getModel()->getPerPage() : 15);

            $paginationFingerprint = "spage:{$pageName}:{$resolvedPage}:per_page:{$effectivePerPage}:cols:" . implode(',', $columns);

            $cacheKey = $customKey !== null
                ? "{$table}:{$customKey}:spaginate:{$paginationFingerprint}"
                : "{$table}:query:spaginate:{$fingerprint}:{$paginationFingerprint}";

            $allTags = !empty($scope) ? array_merge(is_array($scope) ? $scope : [$scope], $tags) : array_merge($autoTags, $tags);

            return \KaziOmar\AtomicCache\Facades\AtomicCache::tags($allTags)->remember(
                $cacheKey,
                $effectiveTtl,
                fn() => $this->simplePaginate($perPage, $columns, $pageName, $page),
                lock: $lock
            );
        };

        \Illuminate\Database\Eloquent\Builder::macro('atomicCacheSimplePaginate', $simplePaginateMacro);
        \Illuminate\Database\Query\Builder::macro('atomicCacheSimplePaginate', $simplePaginateMacro);

        $countMacro = function (
            mixed $columnsOrTtl = '*',
            mixed $keyOrTtl = null,
            mixed $ttl = null,
            array $tags = [],
            bool $lock = false,
            string|array|null $scope = null
        ) use ($resolveQueryMeta, $resolveTtlAndKey) {
            $columns = '*';
            $passedTtl = null;

            if (is_numeric($columnsOrTtl) || $columnsOrTtl instanceof \DateTimeInterface || $columnsOrTtl instanceof \DateInterval) {
                $passedTtl = $columnsOrTtl;
            } elseif (is_string($columnsOrTtl)) {
                $columns = $columnsOrTtl;
            }

            [$table, $modelClass, $fingerprint, $autoTags] = $resolveQueryMeta($this);
            [$customKey, $effectiveTtl] = $resolveTtlAndKey($keyOrTtl, $ttl ?? $passedTtl);

            $cacheKey = $customKey !== null
                ? "{$table}:{$customKey}:count:{$columns}"
                : "{$table}:query:count:{$fingerprint}:{$columns}";

            $allTags = !empty($scope) ? array_merge(is_array($scope) ? $scope : [$scope], $tags) : array_merge($autoTags, $tags);

            return \KaziOmar\AtomicCache\Facades\AtomicCache::tags($allTags)->remember(
                $cacheKey,
                $effectiveTtl,
                fn() => $this->count($columns),
                lock: $lock
            );
        };

        \Illuminate\Database\Eloquent\Builder::macro('atomicCacheCount', $countMacro);
        \Illuminate\Database\Query\Builder::macro('atomicCacheCount', $countMacro);

        $existsMacro = function (
            mixed $keyOrTtl = null,
            mixed $ttl = null,
            array $tags = [],
            bool $lock = false,
            string|array|null $scope = null
        ) use ($resolveQueryMeta, $resolveTtlAndKey) {
            [$table, $modelClass, $fingerprint, $autoTags] = $resolveQueryMeta($this);
            [$customKey, $effectiveTtl] = $resolveTtlAndKey($keyOrTtl, $ttl);

            $cacheKey = $customKey !== null
                ? "{$table}:{$customKey}:exists"
                : "{$table}:query:exists:{$fingerprint}";

            $allTags = !empty($scope) ? array_merge(is_array($scope) ? $scope : [$scope], $tags) : array_merge($autoTags, $tags);

            return \KaziOmar\AtomicCache\Facades\AtomicCache::tags($allTags)->remember(
                $cacheKey,
                $effectiveTtl,
                fn() => $this->exists(),
                lock: $lock
            );
        };

        \Illuminate\Database\Eloquent\Builder::macro('atomicCacheExists', $existsMacro);
        \Illuminate\Database\Query\Builder::macro('atomicCacheExists', $existsMacro);
    }
}
