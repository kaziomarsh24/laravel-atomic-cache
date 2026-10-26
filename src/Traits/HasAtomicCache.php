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

        // Include sanitized HTTP query parameters (e.g. ?page=2&filter=active) while excluding marketing tracking tags (utm_*, fbclid)
        if ($this->shouldIncludeRequestQuery()) {
            $sanitizedQuery = $this->getSanitizedRequestQuery();
            if (!empty($sanitizedQuery)) {
                $safeArgs['__query__'] = $sanitizedQuery;
            }
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
            $sanitizedQuery = $this->getSanitizedRequestQuery();
            if (!empty($sanitizedQuery)) {
                $safeArgs['__query__'] = $sanitizedQuery;
            }
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
                try {
                    $ref = new \ReflectionFunction($arg);
                    return 'Closure:' . md5($ref->getFileName() . ':' . $ref->getStartLine() . ':' . $ref->getEndLine());
                } catch (\Throwable) {
                    return 'Closure';
                }
            }

            if (is_object($arg) && method_exists($arg, 'getKey') && method_exists($arg, 'getTable')) {
                // Eloquent Model
                return get_class($arg) . ':' . $arg->getKey();
            }

            if (is_object($arg) && $arg instanceof \Illuminate\Http\Request) {
                $clean = $arg->except(['_token', '_method']);
                ksort($clean);
                return $clean;
            }

            if (is_object($arg)) {
                if (method_exists($arg, '__toString')) {
                    return (string) $arg;
                }
                if ($arg instanceof \JsonSerializable) {
                    return $arg->jsonSerialize();
                }
                if (method_exists($arg, 'toArray')) {
                    return $arg->toArray();
                }
                return get_class($arg) . ':' . md5(serialize($arg));
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
     * Get sanitized query parameters excluding common analytics/marketing parameters.
     * Prevents cache fragmentation caused by tracking query strings (e.g. fbclid, utm_source).
     */
    protected function getSanitizedRequestQuery(): array
    {
        if (!request() || empty(request()->query())) {
            return [];
        }

        $defaultIgnored = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'fbclid', 'gclid', 'dclid', 'wbraid', 'gbraid', 'ttclid', 'msclkid',
            'mc_cid', 'mc_eid', '_ga', '_gl', '_gid', 'ref',
        ];

        $ignoredKeys = config('atomic-cache.ignored_query_parameters', $defaultIgnored);
        if (property_exists($this, 'ignoredQueryParams') && is_array($this->ignoredQueryParams)) {
            $ignoredKeys = array_merge($ignoredKeys, $this->ignoredQueryParams);
        }

        $query = request()->query();

        foreach ($ignoredKeys as $key) {
            unset($query[$key]);
        }

        // Also strip any wildcard utm_* parameters
        $filtered = array_filter($query, function ($k) {
            return !str_starts_with((string) $k, 'utm_');
        }, ARRAY_FILTER_USE_KEY);

        ksort($filtered);
        return $filtered;
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
