<?php

namespace KaziOmar\AtomicCache;

use Closure;
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
     * Get or compute a cached value.
     */
    public function remember(
        string $key,
        Closure $callback,
        ?int $ttl = null,
        array $tags = [],
        bool $lock = false
    ): mixed {
        if (!config('atomic-cache.enabled', true)) {
            return $callback();
        }

        $ttl = $this->customTtl ?? $ttl ?? config('atomic-cache.default_ttl', 3600);
        $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
        $key = $this->formatKey($key);
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
        Closure $callback,
        ?int $ttl = null,
        array $tags = [],
        ?int $lockSeconds = null,
        ?int $waitSeconds = null
    ): mixed {
        return $this->withLock($lockSeconds, $waitSeconds)
            ->remember($key, $callback, $ttl, $tags, true);
    }

    /**
     * Remember a cached value scoped to a specific user.
     */
    public function rememberForUser(
        string $key,
        Closure $callback,
        ?int $ttl = null,
        int|string|null $userId = null,
        array $tags = []
    ): mixed {
        return $this->forUser($userId)
            ->remember($key, $callback, $ttl, $tags);
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
        $formattedKey = $this->formatKey($key);
        $repository = $this->getRepository($tags);

        try {
            $raw = $repository->get($formattedKey);

            if ($raw === null) {
                return value($default);
            }

            return $this->unpackPayload($raw, $formattedKey, $tags);
        } catch (Throwable $e) {
            return $this->handleCorruptedCache($formattedKey, $tags, $e, $default);
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

        $ttl = $this->customTtl ?? $ttl ?? config('atomic-cache.default_ttl', 3600);
        $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
        $formattedKey = $this->formatKey($key);
        $packed = $this->packPayload($value);

        $repository = $this->getRepository($tags);

        try {
            return $repository->put($formattedKey, $packed, $ttl);
        } catch (Throwable $e) {
            Log::warning("[AtomicCache] Failed to put key '{$formattedKey}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key, array $tags = []): bool
    {
        $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
        $formattedKey = $this->formatKey($key);

        try {
            return $this->getRepository($tags)->forget($formattedKey);
        } catch (Throwable $e) {
            Log::warning("[AtomicCache] Failed to forget key '{$formattedKey}': " . $e->getMessage());
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
                Log::warning("[AtomicCache] Tag flush failed: " . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Clear a specific single-item cache tag (e.g., 'users:12').
     */
    public function clearItem(string $resource, int|string $id): bool
    {
        return $this->clearTags(["{$resource}:{$id}"]);
    }

    /**
     * Clear an entire resource collection tag (e.g., 'users').
     */
    public function clearCollection(string $resource): bool
    {
        return $this->clearTags([$resource]);
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
        $lockKey = "lock:{$key}";

        if (method_exists($store, 'lock')) {
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
     * Check if cache store supports tagging.
     */
    public function supportsTags(Repository $store): bool
    {
        if (method_exists(Cache::class, 'supportsTags')) {
            try {
                return Cache::supportsTags();
            } catch (Throwable) {
                // Continue to check store
            }
        }

        return method_exists($store, 'tags');
    }
}
