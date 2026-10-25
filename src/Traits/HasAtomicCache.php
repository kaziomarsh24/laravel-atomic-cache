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
        array $args = [],
        ?Closure $callback = null,
        ?int $ttl = null,
        bool|array|null $perUserOrTags = null,
        bool $lock = false
    ): mixed {
        if ($callback === null) {
            throw new \InvalidArgumentException("[AtomicCache] Callback closure is required.");
        }
        if (property_exists($this, 'cachingEnabled') && $this->cachingEnabled === false) {
            return $callback();
        }

        $effectiveTtl = $ttl ?? (property_exists($this, 'cacheTTL') ? $this->cacheTTL : null);
        $resource = $this->getCacheResource();
        $safeArgs = $this->normalizeArguments($args);

        // Include HTTP query parameters (e.g. ?page=2&filter=active) so paginated and filtered queries cache distinct results
        if ($this->shouldIncludeRequestQuery()) {
            $safeArgs['__query__'] = request()->query();
        }

        $argHash = md5(serialize($safeArgs));
        $cacheKey = "{$resource}:{$method}:{$argHash}";

        // Handle 5th argument: can be boolean $perUser or explicit array $tags
        $isPerUser = false;
        $effectiveTags = [$resource];

        if (is_bool($perUserOrTags)) {
            $isPerUser = $perUserOrTags;
        } elseif (is_array($perUserOrTags)) {
            $effectiveTags = $perUserOrTags;
        }

        if (!$isPerUser && property_exists($this, 'cachePerUser') && $this->cachePerUser === true) {
            $isPerUser = true;
        }

        $cache = AtomicCache::tags($effectiveTags);

        if ($isPerUser) {
            $cache = $cache->forUser();
        }

        return $cache->remember(
            $cacheKey,
            $callback,
            $effectiveTtl,
            lock: $lock
        );
    }
    /**
     * Standard Laravel 'remember' style for Service Layer.
     * Supports both:
     * - $this->remember('key', 3600, fn() => ...)  // Laravel native order
     * - $this->remember('key', fn() => ..., 3600)  // Closure-first order
     */
    public function remember(
        string $key,
        mixed $ttlOrCallback,
        mixed $callbackOrTtl = null,
        array $tags = [],
        bool $lock = false
    ): mixed {
        if ($ttlOrCallback instanceof Closure) {
            $callback = $ttlOrCallback;
            $ttl = is_int($callbackOrTtl) ? $callbackOrTtl : null;
        } elseif ($callbackOrTtl instanceof Closure) {
            $ttl = is_int($ttlOrCallback) ? $ttlOrCallback : null;
            $callback = $callbackOrTtl;
        } else {
            throw new \InvalidArgumentException("[AtomicCache] remember requires a Closure callback.");
        }

        return $this->rememberCache($key, $callback, $ttl, $tags, $lock);
    }

    /**
     * Cache an ad-hoc custom key with optional tags and atomic lock protection.
     */
    public function rememberCache(
        string $key,
        Closure $callback,
        ?int $ttl = null,
        array $tags = [],
        bool $lock = false
    ): mixed {
        $effectiveTtl = $ttl ?? (property_exists($this, 'cacheTTL') ? $this->cacheTTL : null);
        $effectiveTags = !empty($tags) ? $tags : [$this->getCacheResource()];

        return AtomicCache::tags($effectiveTags)->remember(
            $key,
            $callback,
            $effectiveTtl,
            lock: $lock
        );
    }

    /**
     * Idiomatic name for caching a single resource item by ID.
     */
    public function rememberItem(int|string $id, Closure $callback, ?int $ttl = null): mixed
    {
        $resource = $this->getCacheResource();
        $cacheKey = "{$resource}:{$id}";
        $tags = ["{$resource}:{$id}"];

        return AtomicCache::tags($tags)->remember($cacheKey, $callback, $ttl);
    }

    /**
     * Cache a single resource item by ID with isolated item tag (Alias for rememberItem).
     */
    public function cacheItem(int|string $id, Closure $actualCallback, ?int $ttl = null): mixed
    {
        return $this->rememberItem($id, $actualCallback, $ttl);
    }

    /**
     * Idiomatic name for caching a collection or list query.
     * Automatically isolates paginated and filtered query results based on HTTP query parameters.
     */
    public function rememberCollection(string $key, Closure $callback, ?int $ttl = null, array $extraArgs = []): mixed
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
     * Alias for rememberCollection().
     */
    public function rememberList(string $key, Closure $callback, ?int $ttl = null, array $extraArgs = []): mixed
    {
        return $this->rememberCollection($key, $callback, $ttl, $extraArgs);
    }

    /**
     * Cache a collection or list query with collection tag (Alias for rememberCollection).
     */
    public function cacheCollection(string $key, Closure $actualCallback, ?int $ttl = null, array $extraArgs = []): mixed
    {
        return $this->rememberCollection($key, $actualCallback, $ttl, $extraArgs);
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
     * Invalidate a single item from cache using Laravel's 'forget' terminology.
     */
    public function forgetItem(int|string $id): bool
    {
        $resource = $this->getCacheResource();
        return AtomicCache::forgetItem($resource, $id);
    }

    /**
     * Invalidate cache for a specific item by ID (Alias for forgetItem).
     */
    public function clearItemCache(int|string $id): bool
    {
        return $this->forgetItem($id);
    }

    /**
     * Flush entire collection using Laravel's 'flush' terminology.
     */
    public function flushCollection(): bool
    {
        $resource = $this->getCacheResource();
        return AtomicCache::flushCollection($resource);
    }

    /**
     * Invalidate cache for the entire collection/resource (Alias for flushCollection).
     */
    public function clearCollectionCache(): bool
    {
        return $this->flushCollection();
    }

    /**
     * Flush all caches related to this service resource.
     */
    public function flushAll(): bool
    {
        return $this->flushCollection();
    }

    /**
     * Invalidate both collection and all related caches for this resource (Alias for flushAll).
     */
    public function clearAllCache(): bool
    {
        return $this->flushAll();
    }

    /**
     * Invalidate custom tags directly from service.
     */
    public function flushTags(string|array $tags): bool
    {
        return AtomicCache::flushTags((array) $tags);
    }
}
