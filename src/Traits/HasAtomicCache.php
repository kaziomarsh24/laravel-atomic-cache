<?php

namespace KaziOmar\AtomicCache\Traits;

use Closure;
use Illuminate\Support\Str;
use KaziOmar\AtomicCache\Facades\AtomicCache;

/**
 * Trait HasAtomicCache
 *
 * Provides ultra-simple, declarative caching methods for Services and Repositories.
 */
trait HasAtomicCache
{
    /**
     * Get the base cache tag/resource name for this class (e.g. 'users').
     */
    protected function getCacheResource(): string
    {
        if (property_exists($this, 'cacheResource') && !empty($this->cacheResource)) {
            return $this->cacheResource;
        }

        if (property_exists($this, 'model') && is_object($this->model) && method_exists($this->model, 'getTable')) {
            return $this->model->getTable();
        }

        // Default: derive from class name, e.g. UserService -> users
        $baseName = class_basename(static::class);
        $cleanName = preg_replace('/(Service|Repository|Controller)$/', '', $baseName);

        return Str::snake(Str::plural($cleanName ?: $baseName));
    }

    /**
     * Cache an expensive operation based on method name and arguments.
     * Automatically normalizes complex arguments (Closures, Models) and accounts for request queries (pagination/filters).
     */
    public function cache(
        string $method,
        array $args,
        Closure $callback,
        ?int $ttl = null,
        ?array $tags = null,
        bool $lock = false
    ): mixed {
        $resource = $this->getCacheResource();
        $safeArgs = $this->normalizeArguments($args);

        // Include HTTP query parameters (e.g. ?page=2&filter=active) so paginated and filtered queries cache distinct results
        if ($this->shouldIncludeRequestQuery()) {
            $safeArgs['__query__'] = request()->query();
        }

        $argHash = md5(serialize($safeArgs));
        $cacheKey = "{$resource}:{$method}:{$argHash}";
        $effectiveTags = $tags ?? [$resource];

        return AtomicCache::tags($effectiveTags)->remember(
            $cacheKey,
            $callback,
            $ttl,
            lock: $lock
        );
    }

    /**
     * Cache a single resource item by ID with isolated item tag.
     */
    public function cacheItem(int|string $id, Closure $callback, ?int $ttl = null): mixed
    {
        $resource = $this->getCacheResource();
        $cacheKey = "{$resource}:{$id}";
        $tags = ["{$resource}:{$id}"];

        return AtomicCache::tags($tags)->remember($cacheKey, $callback, $ttl);
    }

    /**
     * Cache a collection or list query with collection tag.
     * Automatically isolates paginated and filtered query results based on HTTP query parameters.
     */
    public function cacheCollection(string $key, Closure $callback, ?int $ttl = null, array $extraArgs = []): mixed
    {
        $resource = $this->getCacheResource();
        $safeArgs = $this->normalizeArguments($extraArgs);

        if ($this->shouldIncludeRequestQuery()) {
            $safeArgs['__query__'] = request()->query();
        }

        $queryHash = !empty($safeArgs) ? ':' . md5(serialize($safeArgs)) : '';
        $cacheKey = "{$resource}:list:{$key}{$queryHash}";
        $tags = [$resource];

        return AtomicCache::tags($tags)->remember($cacheKey, $callback, $ttl);
    }

    /**
     * Safely normalize method arguments to avoid closure serialization errors and huge model payloads.
     */
    protected function normalizeArguments(array $args): array
    {
        return array_map(function ($arg) {
            if ($arg instanceof Closure) {
                return 'Closure#' . spl_object_hash($arg);
            }

            if (is_object($arg) && method_exists($arg, 'getKey') && method_exists($arg, 'getTable')) {
                // Eloquent Model
                return get_class($arg) . ':' . $arg->getKey();
            }

            if (is_object($arg) && $arg instanceof \Illuminate\Http\Request) {
                return $arg->all();
            }

            if (is_object($arg)) {
                if (method_exists($arg, 'toArray')) {
                    return $arg->toArray();
                }
                return get_class($arg) . '#' . spl_object_hash($arg);
            }

            return $arg;
        }, $args);
    }

    /**
     * Check if current environment is an HTTP request with active query parameters.
     */
    protected function shouldIncludeRequestQuery(): bool
    {
        return request() && !empty(request()->query());
    }

    /**
     * Invalidate cache for a specific item by ID.
     */
    public function clearItemCache(int|string $id): bool
    {
        $resource = $this->getCacheResource();
        return AtomicCache::clearItem($resource, $id);
    }

    /**
     * Invalidate cache for the entire collection/resource.
     */
    public function clearCollectionCache(): bool
    {
        $resource = $this->getCacheResource();
        return AtomicCache::clearCollection($resource);
    }

    /**
     * Invalidate both collection and all related caches for this resource.
     */
    public function clearAllCache(): bool
    {
        return $this->clearCollectionCache();
    }
}
