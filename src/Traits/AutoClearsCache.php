<?php

namespace KaziOmar\AtomicCache\Traits;

use KaziOmar\AtomicCache\Facades\AtomicCache;

/**
 * Trait AutoClearsCache
 *
 * Automatically invalidates granular cache tags and related tags on Model save/delete
 * inside model connection afterCommit to prevent race conditions during database transactions.
 * Properly respects multi-database connections and tenant database instances.
 */
trait AutoClearsCache
{
    /**
     * Boot the trait on the model.
     */
    public static function bootAutoClearsCache(): void
    {
        static::created(function ($model) {
            $model->clearModelCacheOnCommit();
        });

        static::updated(function ($model) {
            $model->clearModelCacheOnCommit();
        });

        static::deleted(function ($model) {
            $model->clearModelCacheOnCommit();
        });

        // Safely register 'restored' event only if the Model uses SoftDeletes
        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->clearModelCacheOnCommit();
            });
        }
    }

    /**
     * Clear caches after active database transaction commits on the model's actual connection.
     */
    public function clearModelCacheOnCommit(): void
    {
        $callback = function () {
            $tagsToClear = $this->resolveCacheTagsToClear();
            if (!empty($tagsToClear)) {
                AtomicCache::clearTags($tagsToClear);
            }
        };

        $connection = $this->getConnection();

        if ($connection && method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);
        } else {
            $callback();
        }
    }

    /**
     * Resolve all cache tags that should be invalidated for this model instance.
     */
    public function resolveCacheTagsToClear(): array
    {
        $table = $this->getTable();
        $id = $this->getKey();

        $tags = [];

        // 1. Invalidate single item tag: "users:12" (only if key is present)
        if ($id !== null && $id !== '') {
            $tags[] = "{$table}:{$id}";
        }

        // 2. Invalidate collection tag: "users"
        $tags[] = $table;

        // 3. Invalidate any custom related tags defined on the model
        if (property_exists($this, 'relatedCacheTags') && is_array($this->relatedCacheTags)) {
            $tags = array_merge($tags, $this->relatedCacheTags);
        }

        if (method_exists($this, 'getRelatedCacheTags')) {
            $tags = array_merge($tags, (array) $this->getRelatedCacheTags());
        }

        return array_values(array_unique(array_filter($tags)));
    }
}
