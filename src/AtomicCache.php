<?php

namespace KaziOmar\AtomicCache;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AtomicCache
{
    protected ?string $storeName = null;
    protected array $activeTags = [];
    protected ?int $customTtl = null;
    protected bool $useLock = false;
    protected ?int $lockSeconds = null;
    protected ?int $waitSeconds = null;
    protected ?int $targetUserId = null;
    protected ?float $customJitter = null;

    public function __construct(?string $store = null)
    {
        $this->storeName = $store ?? config('atomic-cache.store');
    }

    /**
     * Set the cache store.
     */
    public function store(?string $name): self
    {
        $clone = clone $this;
        $clone->storeName = $name;
        return $clone;
    }

    /**
     * Attach cache tags to the next operation.
     */
    public function tags(array|string $tags): self
    {
        $clone = clone $this;
        $clone->activeTags = is_array($tags) ? $tags : func_get_args();
        return $clone;
    }

    /**
     * Set a custom TTL for the next operation.
     */
    public function ttl(int $seconds): self
    {
        $clone = clone $this;
        $clone->customTtl = $seconds;
        return $clone;
    }

    /**
     * Enable atomic lock (stampede protection) for the next remember operation.
     */
    public function withLock(?int $lockSeconds = null, ?int $waitSeconds = null): self
    {
        $clone = clone $this;
        $clone->useLock = true;
        $clone->lockSeconds = $lockSeconds;
        $clone->waitSeconds = $waitSeconds;
        return $clone;
    }

    /**
     * Scope the cache to a specific user ID or the current authenticated user.
     */
    public function forUser(int|string|null $userId = null): self
    {
        $clone = clone $this;
        $clone->targetUserId = $userId !== null ? (int) $userId : (Auth::id() ?? 0);
        return $clone;
    }

    /**
     * Apply random TTL jitter (variance) to prevent simultaneous expiry (thundering herd).
     * Pass 0.05 for ±5%, or omit to use configured default percentage.
     */
    public function withJitter(?float $percentage = null): self
    {
        $clone = clone $this;
        $clone->customJitter = $percentage ?? ((float) config('atomic-cache.jitter.percentage', 5) / 100);
        return $clone;
    }

    /**
     * Normalize arguments for remember method.
     * Supports both (key, ttl, callback) and (key, callback, ttl).
     */
    protected function normalizeRememberArgs(mixed $arg2, mixed $arg3): array
    {
        if ($arg2 instanceof Closure) {
            $callback = $arg2;
            $ttl = is_int($arg3) ? $arg3 : null;
        } elseif ($arg3 instanceof Closure) {
            $callback = $arg3;
            $ttl = is_int($arg2) ? $arg2 : null;
        } else {
            throw new \InvalidArgumentException("[AtomicCache] remember requires a Closure callback.");
        }

        return [$callback, $ttl];
    }

    /**
     * Get or compute a cached value.
     * Supports both:
     * - remember($key, 3600, fn() => ...)
     * - remember($key, fn() => ..., 3600)
     */
    public function remember(
        string $key,
        mixed $ttlOrCallback,
        mixed $callbackOrTtl = null,
        array $tags = [],
        bool $lock = false
    ): mixed {
        [$callback, $customTtl] = $this->normalizeRememberArgs($ttlOrCallback, $callbackOrTtl);

        if (!config('atomic-cache.enabled', true)) {
            return $callback();
        }

        $ttl = $this->calculateTtl($customTtl);
        $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
        $shouldLock = $this->useLock || $lock || config('atomic-cache.stampede.enabled', false);

        if ($shouldLock) {
            return $this->rememberWithLockInternal($key, $callback, $ttl, $tags);
        }

        $cached = $this->get($key, null, $tags);
        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->put($key, $value, $ttl, $tags);

        return $value;
    }

    /**
     * Stampede-proof cache remember using atomic distributed locking.
     */
    public function rememberWithLock(
        string $key,
        mixed $ttlOrCallback,
        mixed $callbackOrTtl = null,
        array $tags = [],
        ?int $lockSeconds = null,
        ?int $waitSeconds = null
    ): mixed {
        [$callback, $customTtl] = $this->normalizeRememberArgs($ttlOrCallback, $callbackOrTtl);

        return $this->withLock($lockSeconds, $waitSeconds)
            ->remember($key, $callback, $customTtl, $tags, true);
    }

    /**
     * Remember a cached value scoped to a specific user.
     */
    public function rememberForUser(
        string $key,
        mixed $ttlOrCallback,
        mixed $callbackOrTtl = null,
        int|string|null $userId = null,
        array $tags = []
    ): mixed {
        [$callback, $customTtl] = $this->normalizeRememberArgs($ttlOrCallback, $callbackOrTtl);

        return $this->forUser($userId)
            ->remember($key, $callback, $customTtl, $tags);
    }

    /**
     * Retrieve an item from the cache.
     */
    public function get(string $key, mixed $default = null, array $tags = []): mixed
    {
        if (!config('atomic-cache.enabled', true)) {
            return value($default);
        }

        $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
        $store = $this->getRawStore();
        $effectiveKey = $this->resolveKeyForStore($key, $tags, $store);
        $repository = $this->getRepository($tags);

        try {
            $raw = $repository->get($effectiveKey);

            if ($raw === null) {
                return value($default);
            }

            return $this->unpackPayload($raw, $effectiveKey, $tags);
        } catch (Throwable $e) {
            return $this->handleCorruptedCache($effectiveKey, $tags, $e, $default);
        }
    }

    /**
     * Store an item in the cache with automatic compression.
     */
    public function put(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
    {
        if (!config('atomic-cache.enabled', true)) {
            return false;
        }

        $ttl = $this->calculateTtl($ttl);
        $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
        $store = $this->getRawStore();
        $effectiveKey = $this->resolveKeyForStore($key, $tags, $store);
        $packed = $this->packPayload($value);

        $repository = $this->getRepository($tags);

        try {
            return $repository->put($effectiveKey, $packed, $ttl);
        } catch (Throwable $e) {
            Log::warning("[AtomicCache] Failed to put key '{$effectiveKey}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Determine if an item exists in the cache.
     */
    public function has(string $key, array $tags = []): bool
    {
        $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
        $store = $this->getRawStore();
        $effectiveKey = $this->resolveKeyForStore($key, $tags, $store);

        try {
            return $this->getRepository($tags)->has($effectiveKey);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Store an item permanently in the cache.
     */
    public function forever(string $key, mixed $value, array $tags = []): bool
    {
        return $this->put($key, $value, 315360000, $tags);
    }

    /**
     * Retrieve and delete an item from the cache.
     */
    public function pull(string $key, mixed $default = null, array $tags = []): mixed
    {
        $value = $this->get($key, $default, $tags);
        $this->forget($key, $tags);
        return $value;
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key, array $tags = []): bool
    {
        $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
        $store = $this->getRawStore();
        $effectiveKey = $this->resolveKeyForStore($key, $tags, $store);

        try {
            return $this->getRepository($tags)->forget($effectiveKey);
        } catch (Throwable $e) {
            Log::warning("[AtomicCache] Failed to forget key '{$effectiveKey}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Alias for forget().
     */
    public function clear(string $key, array $tags = []): bool
    {
        return $this->forget($key, $tags);
    }

    /**
     * Clear all cached keys belonging to the given tags.
     * Works with native tags (Redis/Memcached) and graceful virtual tags (File/Database).
     */
    public function clearTags(array|string $tags): bool
    {
        $tagList = is_array($tags) ? $tags : func_get_args();

        if (empty($tagList)) {
            return false;
        }

        $store = $this->getRawStore();

        if ($this->supportsTags($store)) {
            try {
                $store->tags($tagList)->flush();
                return true;
            } catch (Throwable $e) {
                Log::warning("[AtomicCache] Native tag flush failed: " . $e->getMessage());
                return false;
            }
        }

        // Graceful fallback for non-taggable stores (file, database):
        // Increment virtual tag version keys to invalidate all related entries instantly!
        $allSuccess = true;
        foreach ($tagList as $tag) {
            try {
                $tagVersionKey = $this->formatVirtualTagKey($tag);
                $current = (int) $store->get($tagVersionKey, 1);
                $store->forever($tagVersionKey, $current + 1);
            } catch (Throwable $e) {
                Log::warning("[AtomicCache] Virtual tag increment failed for '{$tag}': " . $e->getMessage());
                $allSuccess = false;
            }
        }

        return $allSuccess;
    }

    /**
     * Clear all cached keys belonging to the given tags (Laravel 'flushTags' convention).
     */
    public function flushTags(array|string $tags): bool
    {
        return $this->clearTags($tags);
    }

    /**
     * Flush active tags via chain (AtomicCache::tags(['orders'])->flush())
     * or flush the underlying raw cache store.
     */
    public function flush(): bool
    {
        if (!empty($this->activeTags)) {
            return $this->clearTags($this->activeTags);
        }

        try {
            return $this->getRawStore()->flush();
        } catch (Throwable $e) {
            Log::warning("[AtomicCache] Raw store flush failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear a specific single-item cache tag (e.g., 'users:12').
     */
    public function clearItem(string $resource, int|string $id): bool
    {
        return $this->clearTags(["{$resource}:{$id}"]);
    }

    /**
     * Idiomatic Laravel alias for clearItem().
     */
    public function forgetItem(string $resource, int|string $id): bool
    {
        return $this->clearItem($resource, $id);
    }

    /**
     * Clear an entire resource collection tag (e.g., 'users').
     */
    public function clearCollection(string $resource): bool
    {
        return $this->clearTags([$resource]);
    }

    /**
     * Idiomatic Laravel alias for clearCollection().
     */
    public function flushCollection(string $resource): bool
    {
        return $this->clearCollection($resource);
    }

    /**
     * Internal implementation of stampede-protected remember.
     */
    protected function rememberWithLockInternal(
        string $key,
        Closure $callback,
        int $ttl,
        array $tags
    ): mixed {
        // Fast path: cache hit
        $cached = $this->get($key, null, $tags);
        if ($cached !== null) {
            return $cached;
        }

        $store = $this->getRawStore();
        $lockSeconds = $this->lockSeconds ?? config('atomic-cache.stampede.lock_seconds', 10);
        $waitSeconds = $this->waitSeconds ?? config('atomic-cache.stampede.wait_seconds', 5);
        $lockKey = 'lock:' . $this->formatKey($key);

        if ($this->supportsLock($store)) {
            try {
                $lock = $store->lock($lockKey, $lockSeconds);
                $acquired = $lock->block($waitSeconds);

                if ($acquired) {
                    try {
                        // Double-check: another process might have computed it while we waited
                        $cached = $this->get($key, null, $tags);
                        if ($cached !== null) {
                            return $cached;
                        }

                        $value = $callback();
                        $this->put($key, $value, $ttl, $tags);
                        return $value;
                    } finally {
                        optional($lock)->release();
                    }
                }
            } catch (Throwable $e) {
                // If lock fails or driver does not support lock provider, fallback to executing callback
                Log::info("[AtomicCache] Lock bypass on key '{$key}': " . $e->getMessage());
            }
        }

        // Lock not available or lock acquisition timed out -> compute fallback
        $value = $callback();
        $this->put($key, $value, $ttl, $tags);
        return $value;
    }

    /**
     * Resolve effective key incorporating virtual tag versions if the driver does not support native tags.
     */
    protected function resolveKeyForStore(string $key, array $tags, Repository $store): string
    {
        $formattedKey = $this->formatKey($key);

        if (empty($tags) || $this->supportsTags($store)) {
            return $formattedKey;
        }

        // For non-taggable stores (file, database), append hash of virtual tag versions
        $versions = $this->getVirtualTagVersions($tags, $store);
        return "{$formattedKey}:vt:" . md5(json_encode($versions));
    }

    /**
     * Get the current version numbers for the given tags from the store.
     */
    protected function getVirtualTagVersions(array $tags, Repository $store): array
    {
        $versions = [];
        foreach ($tags as $tag) {
            $tagVersionKey = $this->formatVirtualTagKey($tag);
            $version = $store->get($tagVersionKey);
            $versions[$tag] = $version !== null ? (int) $version : 1;
        }
        return $versions;
    }

    /**
     * Format the cache key for storing a virtual tag's version.
     */
    protected function formatVirtualTagKey(string $tag): string
    {
        $prefix = config('atomic-cache.prefix', 'atomic');
        return "{$prefix}:__tag_v__:{$tag}";
    }

    /**
     * Format key with prefix and optional user scope.
     */
    protected function formatKey(string $key): string
    {
        $prefix = config('atomic-cache.prefix', 'atomic');
        $parts = [];

        if (!empty($prefix)) {
            $parts[] = $prefix;
        }

        if ($this->targetUserId !== null) {
            $parts[] = "user:{$this->targetUserId}";
        }

        $parts[] = $key;

        return implode(':', $parts);
    }

    /**
     * Calculate effective TTL with optional jitter applied.
     */
    protected function calculateTtl(?int $ttl): int
    {
        $baseTtl = $this->customTtl ?? $ttl ?? config('atomic-cache.default_ttl', 3600);
        $jitterEnabled = $this->customJitter !== null || config('atomic-cache.jitter.enabled', false);

        if (!$jitterEnabled || $baseTtl <= 10) {
            return $baseTtl;
        }

        $percentage = $this->customJitter ?? ((float) config('atomic-cache.jitter.percentage', 5) / 100);
        $delta = (int) round($baseTtl * $percentage);

        if ($delta < 1) {
            return $baseTtl;
        }

        return max(1, $baseTtl + random_int(-$delta, $delta));
    }

    /**
     * Pack payload with optional Gzip compression.
     */
    protected function packPayload(mixed $value): mixed
    {
        $compressionEnabled = config('atomic-cache.compression.enabled', true);
        $threshold = config('atomic-cache.compression.threshold', 51200); // 50KB
        $level = config('atomic-cache.compression.level', 6);

        if (!$compressionEnabled || !extension_loaded('zlib')) {
            return [
                '__ac_compressed' => false,
                'data' => $value,
            ];
        }

        try {
            $serialized = serialize($value);

            if (strlen($serialized) >= $threshold) {
                $compressed = gzcompress($serialized, $level);
                if ($compressed !== false) {
                    return [
                        '__ac_compressed' => true,
                        'data' => base64_encode($compressed),
                    ];
                }
            }
        } catch (Throwable) {
            // Serialization failed, fallback to raw
        }

        return [
            '__ac_compressed' => false,
            'data' => $value,
        ];
    }

    /**
     * Unpack payload with automatic decompression.
     */
    protected function unpackPayload(mixed $raw, string $key, array $tags): mixed
    {
        if (!is_array($raw) || !array_key_exists('__ac_compressed', $raw)) {
            // Legacy or direct raw stored value
            return $raw;
        }

        if ($raw['__ac_compressed'] === true) {
            $decoded = base64_decode($raw['data']);
            if ($decoded === false) {
                throw new \RuntimeException("Corrupted base64 payload for key '{$key}'");
            }

            $uncompressed = @gzuncompress($decoded);
            if ($uncompressed === false) {
                throw new \RuntimeException("Decompression failed for key '{$key}'");
            }

            return unserialize($uncompressed);
        }

        return $raw['data'];
    }

    /**
     * Handle corrupted cache recovery (Self-healing).
     */
    protected function handleCorruptedCache(
        string $key,
        array $tags,
        Throwable $exception,
        mixed $default
    ): mixed {
        if (config('atomic-cache.self_healing.log_corrupted', true)) {
            Log::warning("[AtomicCache] Corrupted cache detected for key '{$key}': " . $exception->getMessage());
        }

        if (config('atomic-cache.self_healing.enabled', true)) {
            try {
                $this->getRepository($tags)->forget($key);
            } catch (Throwable) {
                // Ignore cleanup error
            }
        }

        return value($default);
    }

    /**
     * Get the active cache repository (with tags applied if supported).
     */
    protected function getRepository(array $tags = []): Repository
    {
        $store = $this->getRawStore();

        if (!empty($tags) && $this->supportsTags($store)) {
            return $store->tags($tags);
        }

        return $store;
    }

    /**
     * Get the underlying raw cache repository.
     */
    public function getRawStore(): Repository
    {
        return $this->storeName ? Cache::store($this->storeName) : Cache::store();
    }

    /**
     * Check if cache store supports native tagging.
     */
    public function supportsTags(Repository $store): bool
    {
        if (method_exists($store, 'supportsTags')) {
            return (bool) $store->supportsTags();
        }

        if (method_exists($store, 'getStore')) {
            return $store->getStore() instanceof \Illuminate\Cache\TaggableStore;
        }

        return false;
    }

    /**
     * Check if cache store supports atomic locks.
     */
    public function supportsLock(Repository $store): bool
    {
        if (method_exists($store, 'getStore')) {
            $raw = $store->getStore();
            return $raw instanceof LockProvider || method_exists($raw, 'lock');
        }

        return false;
    }
}