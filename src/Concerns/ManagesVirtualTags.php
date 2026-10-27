<?php

namespace KaziOmar\AtomicCache\Concerns;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

trait ManagesVirtualTags
{
    /**
     * Clear all cached keys belonging to the given tags.
     * Works with native tags (Redis/Memcached) and graceful virtual tags (File/Database).
     * Accepts strings, Model instances, or Type-Safe Model classes.
     */
    public function clearTags(array|string $tags): bool
    {
        $raw = is_array($tags) ? $tags : func_get_args();
        $tagList = $this->normalizeTags($raw);

        if (empty($tagList)) {
            return false;
        }

        $store = $this->getRawStore();

        // 1. Rotate OCC version tokens for all tags across ALL drivers (Redis, Memcached, File, Database, Array)
        // Universal UUID tokens eliminate eviction counter collisions and guarantee OCC across all stores
        $allSuccess = true;
        foreach ($tagList as $tag) {
            try {
                $tagVersionKey = $this->formatVirtualTagKey($tag);
                $store->forever($tagVersionKey, (string) Str::uuid());
            } catch (Throwable $e) {
                $allSuccess = false;
                Log::warning("[AtomicCache] Failed to invalidate tag version token '{$tag}': " . $e->getMessage());
            }
        }

        // 2. Native taggable store (Redis, Memcached): Flush underlying native tag sets
        if ($this->supportsTags($store)) {
            try {
                $this->purgeRedisTaggedKeysIfNeeded($store, $tagList);
                $store->tags($tagList)->flush();
                return true;
            } catch (Throwable $e) {
                Log::warning("[AtomicCache] Native tag flush failed: " . $e->getMessage());
                return false;
            }
        }

        return $allSuccess;
    }

    /**
     * Alias for clearTags() matching Laravel Cache's flush nomenclature.
     */
    public function flushTags(array|string $tags): bool
    {
        return $this->clearTags($tags);
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

        // For non-taggable stores (file, database), append hash of virtual tag versions (ksorted for order-independent hashing)
        $versions = $this->getVirtualTagVersions($tags, $store);
        ksort($versions);
        return "{$formattedKey}:vt:" . md5(json_encode($versions));
    }

    /**
     * Retrieve current version tokens for the given virtual tags.
     * If a tag has never been versioned, initialize with a stable version.
     */
    protected function getVirtualTagVersions(array $tags, Repository $store): array
    {
        $versions = [];

        foreach ($tags as $tag) {
            $tagVersionKey = $this->formatVirtualTagKey($tag);
            $version = $store->get($tagVersionKey);

            if ($version === null) {
                $version = (string) Str::uuid();
                $store->forever($tagVersionKey, $version);
            }

            $versions[$tag] = $version;
        }

        ksort($versions);
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
     * Normalize tags from mixed inputs (strings, Eloquent models, class names, numbers, nested arrays).
     */
    public function normalizeTags(array $tags): array
    {
        $normalized = [];
        $flattened = Arr::flatten($tags);

        foreach ($flattened as $tag) {
            if ($tag instanceof Model) {
                $table = $tag->getTable();
                $id = $tag->getKey();
                if ($id !== null && $id !== '') {
                    $normalized[] = "{$table}:{$id}";
                }
                $normalized[] = $table;
            } elseif (is_string($tag) && str_contains($tag, '\\') && class_exists($tag) && is_subclass_of($tag, Model::class)) {
                // Type-safe model class (e.g. Product::class -> 'products')
                $normalized[] = (new $tag)->getTable();
            } elseif ((is_string($tag) || is_numeric($tag)) && (string) $tag !== '') {
                $normalized[] = (string) $tag;
            }
        }

        return array_values(array_unique(array_filter($normalized)));
    }

    /**
     * Get a unique version identifier for the given tags (works with native and virtual tags).
     * Used for optimistic concurrency control (OCC) to discard stale writes when tags are invalidated mid-flight.
     */
    public function getTagVersionIdentifier(array $tags, Repository $store): string
    {
        if (empty($tags)) {
            return '';
        }

        $normalized = $this->normalizeTags($tags);
        if (empty($normalized)) {
            return '';
        }

        // Retrieve dedicated UUID version tokens for these tags.
        // Works universally across Redis, Memcached, File, Database, and Array drivers!
        $versions = $this->getVirtualTagVersions($normalized, $store);
        ksort($versions);
        return md5(json_encode($versions));
    }

    /**
     * Purge Redis tagged cache keys directly from Redis sorted sets.
     * Works around upstream Laravel 10 incompatibility with phpredis >= 6.1.0
     * where RedisTagSet::entries() starts with cursor '0' instead of null,
     * causing TaggedCache::flush() to silently fail to scan or delete any keys.
     * On Laravel 11+, native flush is already fixed upstream and executes natively.
     */
    protected function purgeRedisTaggedKeysIfNeeded(Repository $store, array $tagList): void
    {
        if (!$this->shouldPurgeRedisTaggedKeysDirectly()) {
            return;
        }

        try {
            $rawStore = method_exists($store, 'getStore') ? $store->getStore() : null;
            if ($rawStore instanceof \Illuminate\Cache\RedisStore) {
                $connection = $rawStore->connection();
                $prefix = $rawStore->getPrefix();

                foreach ($tagList as $tag) {
                    $tagKey = $prefix . "tag:{$tag}:entries";

                    // Paginate entries in 1,000-key slices to avoid high memory spikes on large sets
                    while (true) {
                        $entries = $connection->zrange($tagKey, 0, 999);
                        if (empty($entries) || !is_array($entries)) {
                            break;
                        }

                        $keysToDelete = [];
                        foreach ($entries as $entry) {
                            if (is_string($entry) && $entry !== '') {
                                $keysToDelete[] = $prefix . $entry;
                            }
                        }

                        if (!empty($keysToDelete)) {
                            try {
                                $connection->unlink(...$keysToDelete);
                            } catch (Throwable) {
                                try {
                                    $connection->del(...$keysToDelete);
                                } catch (Throwable) {
                                    foreach ($keysToDelete as $singleKey) {
                                        try {
                                            $connection->del($singleKey);
                                        } catch (Throwable) {}
                                    }
                                }
                            }
                        }

                        $connection->zremrangebyrank($tagKey, 0, count($entries) - 1);
                    }

                    try {
                        $connection->unlink($tagKey);
                    } catch (Throwable) {
                        try {
                            $connection->del($tagKey);
                        } catch (Throwable) {}
                    }
                }
            }
        } catch (Throwable $e) {
            Log::debug("[AtomicCache] Direct Redis tag purge encountered an exception: " . $e->getMessage());
        }
    }

    /**
     * Determine if direct Redis tag purging is required.
     * Only needed on Laravel < 11.0.0 when running PhpRedis >= 6.1.0, where
     * upstream RedisTagSet::entries() starts with cursor '0' instead of null,
     * causing TaggedCache::flush() to silently fail to scan or delete any keys.
     */
    protected function shouldPurgeRedisTaggedKeysDirectly(): bool
    {
        $laravelVersion = function_exists('app') ? app()->version() : null;
        if ($laravelVersion !== null && version_compare($laravelVersion, '11.0.0', '>=')) {
            return false;
        }

        if (!extension_loaded('redis')) {
            return false;
        }

        $redisVersion = phpversion('redis');
        return $redisVersion !== false && version_compare($redisVersion, '6.1.0', '>=');
    }

}


