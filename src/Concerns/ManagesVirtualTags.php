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

        // Native taggable store (Redis, Memcached):
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
        // Update virtual tag version tokens to invalidate all related entries instantly!
        $allSuccess = true;
        foreach ($tagList as $tag) {
            try {
                $tagVersionKey = $this->formatVirtualTagKey($tag);
                $store->forever($tagVersionKey, (string) Str::uuid());
            } catch (Throwable $e) {
                $allSuccess = false;
                Log::warning("[AtomicCache] Failed to invalidate virtual tag '{$tag}': " . $e->getMessage());
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
}
