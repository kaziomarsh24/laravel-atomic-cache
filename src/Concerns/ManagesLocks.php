<?php

namespace KaziOmar\AtomicCache\Concerns;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

trait ManagesLocks
{
    /**
     * Enable atomic distributed locking for stampede protection.
     */
    public function withLock(?int $lockSeconds = null, ?int $waitSeconds = null): self
    {
        $clone = clone $this;
        $clone->useLock = true;
        if ($lockSeconds !== null) {
            $clone->lockSeconds = $lockSeconds;
        }
        if ($waitSeconds !== null) {
            $clone->waitSeconds = $waitSeconds;
        }
        return $clone;
    }

    /**
     * Stampede protection internal execution using atomic distributed locking.
     * Uses double-checked locking: once the lock is acquired, it checks cache again
     * to ensure another process hasn't already computed and cached the value.
     */
    protected function rememberWithLockInternal(
        string $key,
        Closure $callback,
        ?int $ttl = null,
        array $tags = []
    ): mixed {
        if (method_exists($this, 'shouldBypassCache') && $this->shouldBypassCache()) {
            return $callback();
        }

        // Fast path: cache hit
        $sentinel = new \stdClass();
        $cached = $this->get($key, $sentinel, $tags);
        if ($cached !== $sentinel) {
            return $cached;
        }

        $store = $this->getRawStore();
        $lockSeconds = $this->lockSeconds ?? config('atomic-cache.stampede.lock_seconds', 10);
        $waitSeconds = $this->waitSeconds ?? config('atomic-cache.stampede.wait_seconds', 5);
        $lockKey = 'lock:' . $this->formatKey($key);

        if ($this->supportsLock($store)) {
            $lock = null;
            $acquired = false;

            try {
                $lock = $store->lock($lockKey, $lockSeconds);
                $acquired = $lock->block($waitSeconds);
            } catch (Throwable $e) {
                // If lock fails or driver does not support lock provider, fallback to executing callback
                Log::info("[AtomicCache] Lock bypass on key '{$key}': " . $e->getMessage());
            }

            if ($acquired) {
                try {
                    // Double-checked locking: re-check if another process populated cache while waiting
                    $recheck = $this->get($key, $sentinel, $tags);
                    if ($recheck !== $sentinel) {
                        return $recheck;
                    }

                    $normalizedTags = $this->normalizeTags($tags);
                    $tagVersionsBefore = !empty($normalizedTags) ? $this->getVirtualTagVersions($normalizedTags, $store) : [];
                    $tagVersionBefore = !empty($tagVersionsBefore) ? md5(json_encode($tagVersionsBefore)) : '';

                    $value = $callback();

                    $tagVersionsAfter = !empty($normalizedTags) ? $this->getVirtualTagVersions($normalizedTags, $store) : [];
                    $tagVersionAfter = !empty($tagVersionsAfter) ? md5(json_encode($tagVersionsAfter)) : '';

                    if ($tagVersionBefore === $tagVersionAfter) {
                        $this->put($key, $value, $ttl, $tags, $tagVersionsBefore);
                    }
                    return $value;
                } finally {
                    try {
                        $lock->release();
                    } catch (Throwable) {
                        // Suppress lock release errors
                    }
                }
            }
        }

        // Fallback if lock could not be acquired or driver does not support locks
        $normalizedTags = $this->normalizeTags($tags);
        $tagVersionsBefore = !empty($normalizedTags) ? $this->getVirtualTagVersions($normalizedTags, $store) : [];
        $tagVersionBefore = !empty($tagVersionsBefore) ? md5(json_encode($tagVersionsBefore)) : '';

        $value = $callback();

        $tagVersionsAfter = !empty($normalizedTags) ? $this->getVirtualTagVersions($normalizedTags, $store) : [];
        $tagVersionAfter = !empty($tagVersionsAfter) ? md5(json_encode($tagVersionsAfter)) : '';

        if ($tagVersionBefore === $tagVersionAfter) {
            $this->put($key, $value, $ttl, $tags, $tagVersionsBefore);
        }
        return $value;
    }

    /**
     * Check if a cache store supports atomic distributed locks.
     * Inspects underlying store for LockProvider contract since Repository proxies locks via __call.
     */
    public function supportsLock(mixed $store): bool
    {
        if (!is_object($store)) {
            return false;
        }
        if (method_exists($store, 'getStore')) {
            $raw = $store->getStore();
            return $raw instanceof LockProvider || method_exists($raw, 'lock');
        }

        return $store instanceof LockProvider || method_exists($store, 'lock');
    }
}
