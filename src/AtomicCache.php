<?php

namespace KaziOmar\AtomicCache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use KaziOmar\AtomicCache\Concerns\ManagesChunking;
use KaziOmar\AtomicCache\Concerns\ManagesCompression;
use KaziOmar\AtomicCache\Concerns\ManagesLocks;
use KaziOmar\AtomicCache\Concerns\ManagesVirtualTags;
use KaziOmar\AtomicCache\Exceptions\ChunkRestorationException;
use Throwable;

class AtomicCache
{
    use ManagesChunking,
        ManagesCompression,
        ManagesLocks,
        ManagesVirtualTags;

    protected ?string $activeStore = null;
    protected array $activeTags = [];
    protected int|string|null $targetUserId = null;
    protected ?int $customTtl = null;
    protected ?float $customJitter = null;
    protected bool $useLock = false;
    protected ?int $lockSeconds = null;
    protected ?int $waitSeconds = null;
    protected ?bool $bypassDuringTransactions = null;

    public function __construct(?string $store = null)
    {
        $this->activeStore = $store ?? config('atomic-cache.store', config('cache.default'));
    }

    /**
     * Set the cache store to use dynamically.
     */
    public function store(?string $name): self
    {
        $clone = clone $this;
        $clone->activeStore = $name;
        return $clone;
    }

    /**
     * Set cache tags (native or virtual) for scoped operations.
     */
    public function tags(array|string $tags): self
    {
        $clone = clone $this;
        $clone->activeTags = $this->normalizeTags((array) $tags);
        return $clone;
    }

    /**
     * Set explicit TTL in seconds or date representation for this query/operation.
     */
    public function ttl(int|DateTimeInterface|DateInterval $seconds): self
    {
        $clone = clone $this;
        $clone->customTtl = static::parseTtlSeconds($seconds);
        return $clone;
    }

    /**
     * Scope cache operations to a specific authenticated or guest user.
     * Prevents multi-user data leakage.
     */
    public function forUser(int|string|null $userId = null): self
    {
        $clone = clone $this;
        if ($userId !== null) {
            $clone->targetUserId = $userId;
        } else {
            $authId = Auth::id();
            if ($authId !== null) {
                $clone->targetUserId = $authId;
            } elseif (function_exists('session') && session()->isStarted() && session()->getId()) {
                $clone->targetUserId = 'guest:' . session()->getId();
            } else {
                $clone->targetUserId = 'guest';
            }
        }
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
     * Parse any TTL input (int, Carbon, DateInterval) into clean integer seconds.
     */
    public static function parseTtlSeconds(mixed $ttl): ?int
    {
        if ($ttl instanceof DateTimeInterface) {
            return max(0, $ttl->getTimestamp() - time());
        }

        if ($ttl instanceof DateInterval) {
            return (new \DateTime())->add($ttl)->getTimestamp() - time();
        }

        if (is_numeric($ttl)) {
            return (int) $ttl;
        }

        return null;
    }

    /**
     * Resolve callback and TTL from any permutation of positional or named arguments.
     * Supports:
     * - ($ttl, $callback)
     * - ($callback, $ttl)
     * - ($ttl, callback: $callback)
     * - (ttl: $ttl, callback: $callback)
     * - (callback: $callback, ttl: $ttl)
     */
    public static function resolveRememberArguments(
        mixed $arg2 = null,
        mixed $arg3 = null,
        mixed $ttl = null,
        ?Closure $callback = null
    ): array {
        $candidates = [$arg2, $arg3, $callback, $ttl];
        $resolvedCallback = null;
        $resolvedTtl = null;

        foreach ($candidates as $candidate) {
            if ($candidate instanceof Closure && $resolvedCallback === null) {
                $resolvedCallback = $candidate;
            } elseif ($candidate !== null && !($candidate instanceof Closure) && $resolvedTtl === null) {
                $resolvedTtl = static::parseTtlSeconds($candidate);
            }
        }

        if ($resolvedCallback === null) {
            throw new \InvalidArgumentException("[AtomicCache] remember requires a Closure callback.");
        }

        return [$resolvedCallback, $resolvedTtl];
    }

    /**
     * Normalize arguments for remember method.
     */
    protected function normalizeRememberArgs(
        mixed $arg2 = null,
        mixed $arg3 = null,
        mixed $ttl = null,
        ?Closure $callback = null
    ): array {
        return static::resolveRememberArguments($arg2, $arg3, $ttl, $callback);
    }

    /**
     * Get or compute a cached value.
     * Supports:
     * - remember($key, 3600, fn() => ...)
     * - remember($key, fn() => ..., 3600)
     * - remember($key, ttl: 3600, callback: fn() => ...)
     */

    /**
     * Disable transaction bypass explicitly for this instance.
     */
    public function withoutTransactionBypass(): self
    {
        $clone = clone $this;
        $clone->bypassDuringTransactions = false;
        return $clone;
    }

    /**
     * Enable transaction bypass explicitly for this instance.
     */
    public function withTransactionBypass(): self
    {
        $clone = clone $this;
        $clone->bypassDuringTransactions = true;
        return $clone;
    }

    /**
     * Determine whether cache operations should be completely bypassed.
     */
    public function shouldBypassCache(): bool
    {
        if (!config('atomic-cache.enabled', true)) {
            return true;
        }

        $bypassOnTx = $this->bypassDuringTransactions ?? (bool) config('atomic-cache.bypass_during_transactions', true);
        if ($bypassOnTx && $this->isInsideActiveTransaction()) {
            return true;
        }

        return false;
    }

    /**
     * Check if the current process is inside an active, uncommitted database transaction.
     * Prevents dirty reads and phantom cache writes during rollbacks.
     */
    public function isInsideActiveTransaction(): bool
    {
        if (!function_exists('app') || !app()->bound('db')) {
            return false;
        }

        try {
            $db = app('db');

            if (method_exists($db, 'transactionLevel') && $db->transactionLevel() > 0) {
                return true;
            }

            if (method_exists($db, 'getConnections')) {
                foreach ($db->getConnections() as $connection) {
                    if (method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function remember(
        string $key,
        mixed $ttlOrCallback = null,
        mixed $callbackOrTtl = null,
        array $tags = [],
        bool $lock = false,
        mixed $ttl = null,
        ?Closure $callback = null
    ): mixed {
        [$resolvedCallback, $customTtl] = $this->normalizeRememberArgs($ttlOrCallback, $callbackOrTtl, $ttl, $callback);

        if ($this->shouldBypassCache()) {
            return $resolvedCallback();
        }

        $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
        $shouldLock = $this->useLock || $lock || config('atomic-cache.stampede.enabled', false);

        if ($shouldLock) {
            return $this->rememberWithLockInternal($key, $resolvedCallback, $customTtl, $tags);
        }

        $sentinel = new \stdClass();
        $cached = $this->get($key, $sentinel, $tags);
        if ($cached !== $sentinel) {
            return $cached;
        }

        $store = $this->getRawStore();
        $normalizedTags = $this->normalizeTags($tags);
        $tagVersionsBefore = !empty($normalizedTags) ? $this->getVirtualTagVersions($normalizedTags, $store) : [];
        $tagVersionBefore = !empty($tagVersionsBefore) ? md5(json_encode($tagVersionsBefore)) : '';

        $value = $resolvedCallback();

        // Discard stale writes if tags were invalidated while callback was running
        $tagVersionsAfter = !empty($normalizedTags) ? $this->getVirtualTagVersions($normalizedTags, $store) : [];
        $tagVersionAfter = !empty($tagVersionsAfter) ? md5(json_encode($tagVersionsAfter)) : '';

        if ($tagVersionBefore === $tagVersionAfter) {
            $this->put($key, $value, $customTtl, $tags, $tagVersionsBefore);
        }

        return $value;
    }

    /**
     * Stampede-proof cache remember using atomic distributed locking.
     */
    public function rememberWithLock(
        string $key,
        mixed $ttlOrCallback = null,
        mixed $callbackOrTtl = null,
        array $tags = [],
        ?int $lockSeconds = null,
        ?int $waitSeconds = null,
        mixed $ttl = null,
        ?Closure $callback = null
    ): mixed {
        [$resolvedCallback, $customTtl] = $this->normalizeRememberArgs($ttlOrCallback, $callbackOrTtl, $ttl, $callback);

        return $this->withLock($lockSeconds, $waitSeconds)
            ->remember($key, $resolvedCallback, $customTtl, $tags, true);
    }

    /**
     * Remember a cached value scoped to a specific user.
     */
    public function rememberForUser(
        string $key,
        mixed $ttlOrCallback = null,
        mixed $callbackOrTtl = null,
        int|string|null $userId = null,
        array $tags = [],
        mixed $ttl = null,
        ?Closure $callback = null
    ): mixed {
        [$resolvedCallback, $customTtl] = $this->normalizeRememberArgs($ttlOrCallback, $callbackOrTtl, $ttl, $callback);

        return $this->forUser($userId)
            ->remember($key, $resolvedCallback, $customTtl, $tags);
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

            return $this->unpackPayload($raw, $effectiveKey, $tags, $repository);
        } catch (Throwable $e) {
            return $this->handleGetException($effectiveKey, $tags, $e, $default);
        }
    }

    /**
     * Store an item in the cache with automatic compression, chunking, and write deduplication.
     */
    public function put(string $key, mixed $value, mixed $ttl = null, array $tags = [], ?array $knownTagVersions = null): bool
    {
        if (!config('atomic-cache.enabled', true)) {
            return false;
        }

        try {
            $parsedTtl = static::parseTtlSeconds($ttl);
            if ($parsedTtl !== null && $parsedTtl <= 0) {
                return $this->forget($key, $tags);
            }
            $ttlSeconds = $this->calculateTtl($parsedTtl);
            $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
            $store = $this->getRawStore();
            $effectiveKey = $this->resolveKeyForStore($key, $tags, $store, $knownTagVersions);
            $repository = $this->getRepository($tags);

            // 1. Write Deduplication (Cache DNA)
            $dnaKey = "{$effectiveKey}:__dna__";
            $dna = null;
            if (config('atomic-cache.deduplication.enabled', false)) {
                try {
                    $dna = md5(serialize($value));
                    $existingDna = $repository->get($dnaKey);
                    if ($existingDna !== null && $existingDna === $dna && $repository->has($effectiveKey)) {
                        // Content is identical and already exists in cache: refresh TTL without re-serializing
                        $repository->put($dnaKey, $dna, $ttlSeconds);
                        $this->refreshKeyTtl($effectiveKey, $ttlSeconds, $repository);
                        return true;
                    }
                } catch (Throwable) {
                    // Ignore DNA check failure
                }
            }

            // 2. Transparent Chunking (for large arrays or collections)
            if ($this->shouldChunk($value)) {
                $success = $this->putChunked($effectiveKey, $value, $ttlSeconds, $tags, $repository);
                if ($success && $dna !== null) {
                    try {
                        $repository->put($dnaKey, $dna, $ttlSeconds);
                    } catch (Throwable) {}
                }
                return $success;
            }

            // Only clean old chunks on non-chunked put if chunking is enabled and clean_on_put is opted-in
            if (config('atomic-cache.chunking.enabled', true) && config('atomic-cache.chunking.clean_on_put', false)) {
                $this->cleanExistingChunks($effectiveKey, $repository);
            }

            // 3. Normal / Compressed Storage
            $packed = $this->packPayload($value);
            $stored = $repository->put($effectiveKey, $packed, $ttlSeconds);

            if ($stored && $dna !== null) {
                try {
                    $repository->put($dnaKey, $dna, $ttlSeconds);
                } catch (Throwable) {}
            }

            return (bool) $stored;
        } catch (Throwable $e) {
            Log::warning("[AtomicCache] Put failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if an item exists in the cache.
     */
    public function has(string $key, array $tags = []): bool
    {
        if (!config('atomic-cache.enabled', true)) {
            return false;
        }

        try {
            $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
            $store = $this->getRawStore();
            $effectiveKey = $this->resolveKeyForStore($key, $tags, $store);
            $repository = $this->getRepository($tags);

            return (bool) $repository->has($effectiveKey);
        } catch (Throwable $e) {
            Log::warning("[AtomicCache] Has check failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value, array $tags = []): bool
    {
        return $this->put($key, $value, 315360000, $tags); // 10 years
    }

    /**
     * Retrieve an item and then delete it.
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
        try {
            $tags = !empty($this->activeTags) ? $this->activeTags : $tags;
            $store = $this->getRawStore();
            $effectiveKey = $this->resolveKeyForStore($key, $tags, $store);
            $repository = $this->getRepository($tags);

            $this->cleanExistingChunks($effectiveKey, $repository);

            if (config('atomic-cache.deduplication.enabled', false)) {
                try {
                    $repository->forget("{$effectiveKey}:__dna__");
                } catch (Throwable) {}
            }

            return (bool) $repository->forget($effectiveKey);
        } catch (Throwable $e) {
            Log::warning("[AtomicCache] Forget failed for key '{$key}': " . $e->getMessage());
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
     * Flush cached items.
     * When tags are active, flushes only those specific tags.
     * When called on raw store, flushes the entire cache store.
     */
    public function flush(): bool
    {
        if (!empty($this->activeTags)) {
            return $this->clearTags($this->activeTags);
        }

        try {
            return $this->getRawStore()->flush();
        } catch (Throwable $e) {
            Log::warning('[AtomicCache] Flush failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Invalidate a single item by resource and ID.
     */
    public function clearItem(string $resource, int|string $id): bool
    {
        return $this->clearTags(["{$resource}:{$id}"]);
    }

    /**
     * Alias for clearItem().
     */
    public function forgetItem(string $resource, int|string $id): bool
    {
        return $this->clearItem($resource, $id);
    }

    /**
     * Clear all items in a resource collection.
     */
    public function clearCollection(string $resource): bool
    {
        return $this->clearTags([$resource]);
    }

    /**
     * Alias for clearCollection().
     */
    public function flushCollection(string $resource): bool
    {
        return $this->clearCollection($resource);
    }

    /**
     * Execute a callback and ensure specified models, collections, or tags are invalidated.
     * If executed within an active database transaction, invalidation is safely deferred
     * to afterCommit to prevent stale dirty reads or race conditions.
     */
    public function invalidating(mixed $targets, Closure $callback): mixed
    {
        $result = $callback();

        $tagsToClear = $this->resolveInvalidatingTags($targets);

        if (!empty($tagsToClear)) {
            $clearFn = function () use ($tagsToClear) {
                $this->clearTags($tagsToClear);
            };

            if ($this->isInsideActiveTransaction()) {
                $this->registerAfterCommitCallback($clearFn);
            } else {
                $clearFn();
            }
        }

        return $result;
    }

    /**
     * Resolve tags to clear from mixed targets (class names, model instances, collections, or tag strings).
     *
     * @throws \InvalidArgumentException When an unsupported target type is encountered.
     */
    protected function resolveInvalidatingTags(mixed $targets): array
    {
        $items = $this->normalizeInvalidatingTargets($targets);
        $tags = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item instanceof \Illuminate\Database\Eloquent\Model) {
                if (method_exists($item, 'resolveCacheTagsToClear')) {
                    $tags = array_merge($tags, $item->resolveCacheTagsToClear(false));
                } else {
                    $tags[] = $item->getTable();
                    if ($item->getKey() !== null) {
                        $tags[] = "{$item->getTable()}:{$item->getKey()}";
                    }
                }
            } elseif (is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed === '') {
                    continue;
                }

                if (class_exists($trimmed) && is_subclass_of($trimmed, \Illuminate\Database\Eloquent\Model::class)) {
                    $instance = new $trimmed();
                    $tags[] = $instance->getTable();
                } else {
                    // Plain tag string (e.g. 'orders' or 'stores:5')
                    $tags[] = $trimmed;
                }
            } else {
                $type = get_debug_type($item);
                $valueHint = is_scalar($item) ? " ({$item})" : "";
                throw new \InvalidArgumentException(
                    "AtomicCache::invalidating() expects targets to be an Eloquent Model, Model class-string, cache tag string, or an iterable/Collection of them. Received [{$type}{$valueHint}]." .
                    (is_numeric($item) ? " If you want to invalidate a specific model ID, pass 'table:{$item}' or the Model instance." : "")
                );
            }
        }

        return array_values(array_unique(array_filter($tags)));
    }

    /**
     * Recursively flatten iterables and collections into a flat list of individual targets.
     */
    protected function normalizeInvalidatingTargets(mixed $targets): array
    {
        if (is_iterable($targets)) {
            $flattened = [];
            foreach ($targets as $target) {
                if (is_iterable($target) && !($target instanceof \Illuminate\Database\Eloquent\Model)) {
                    $flattened = array_merge($flattened, $this->normalizeInvalidatingTargets($target));
                } else {
                    $flattened[] = $target;
                }
            }
            return $flattened;
        }

        return [$targets];
    }

    /**
     * Register a callback to run after the active database transaction commits.
     */
    protected function registerAfterCommitCallback(Closure $callback): void
    {
        if (!function_exists('app') || !app()->bound('db')) {
            $callback();
            return;
        }

        try {
            $db = app('db');

            $registered = false;
            if (method_exists($db, 'getConnections')) {
                foreach ($db->getConnections() as $connection) {
                    if (method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
                        if (method_exists($connection, 'afterCommit')) {
                            $connection->afterCommit($callback);
                            $registered = true;
                        }
                    }
                }
            }

            if (!$registered) {
                if (method_exists($db, 'connection')) {
                    $conn = $db->connection();
                    if (method_exists($conn, 'afterCommit')) {
                        $conn->afterCommit($callback);
                        $registered = true;
                    }
                }
            }

            if (!$registered) {
                $callback();
            }
        } catch (\Throwable) {
            $callback();
        }
    }

    /**
     * Handle exceptions encountered during get() operations.
     * Distinguishes storage network/connection errors from corrupted cache payloads.
     */
    protected function handleGetException(
        string $key,
        array $tags,
        Throwable $exception,
        mixed $default
    ): mixed {
        if ($this->isConnectionException($exception)) {
            Log::warning("[AtomicCache] Storage connection failed for key '{$key}': " . $exception->getMessage());
            return value($default);
        }

        return $this->handleCorruptedCache($key, $tags, $exception, $default);
    }

    /**
     * Determine if an exception represents a storage connection or network failure.
     */
    protected function isConnectionException(Throwable $e): bool
    {
        $class = get_class($e);
        $message = strtolower($e->getMessage());

        return str_contains($class, 'Connection')
            || str_contains($class, 'RedisException')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'went away')
            || str_contains($message, 'timed out')
            || str_contains($message, 'network is unreachable')
            || str_contains($message, 'no route to host');
    }

    /**
     * Handle corrupted cache entries by self-healing (logging & purging broken keys).
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
                $repository = $this->getRepository($tags);

                // If a chunk read failed, check if the manifest has moved to a newer generation.
                // If so, do NOT purge the active cache key or newer generation chunks!
                if ($exception instanceof ChunkRestorationException) {
                    $failedGeneration = $exception->getGeneration();
                    if ($failedGeneration !== null) {
                        $currentManifest = $repository->get($key);
                        $currentGeneration = is_array($currentManifest) ? ($currentManifest['generation'] ?? null) : null;

                        if ($currentGeneration !== null && $currentGeneration !== $failedGeneration) {
                            Log::info("[AtomicCache] Superseded generation '{$failedGeneration}' chunk missing for key '{$key}'; active generation '{$currentGeneration}' preserved.");
                            return value($default);
                        }
                    }
                }

                $this->cleanExistingChunks($key, $repository);
                $repository->forget("{$key}:__dna__");
                $repository->forget($key);
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
     * Get the underlying raw cache repository without tags.
     */
    public function getRawStore(): Repository
    {
        return Cache::store($this->activeStore);
    }

    /**
     * Check if a cache store supports native tags.
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
     * Format a key with prefix and optional user scoping.
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
        $baseTtl = $ttl ?? $this->customTtl ?? (int) config('atomic-cache.default_ttl', 3600);
        $baseTtl = max(1, $baseTtl);

        $jitterEnabled = config('atomic-cache.jitter.enabled', false);
        if (!$jitterEnabled && $this->customJitter === null) {
            return $baseTtl;
        }

        $percentage = $this->customJitter ?? ((float) config('atomic-cache.jitter.percentage', 5) / 100);
        // Clamp jitter to max 50% to prevent non-positive TTL
        $percentage = max(0.0, min(0.5, $percentage));
        $delta = (int) round($baseTtl * $percentage);

        if ($delta <= 0) {
            return $baseTtl;
        }

        return max(1, $baseTtl + random_int(-$delta, $delta));
    }
}
