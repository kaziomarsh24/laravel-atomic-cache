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

        // 3. Invalidate declarative parent relations ($cacheTouches, $clearsRelations, $cacheParents)
        $parentTags = $this->resolveParentRelationCacheTags();
        if (!empty($parentTags)) {
            $tags = array_merge($tags, $parentTags);
        }

        // 4. Invalidate any custom related tags defined on the model
        if (property_exists($this, 'relatedCacheTags') && is_array($this->relatedCacheTags)) {
            $tags = array_merge($tags, $this->relatedCacheTags);
        }

        if (method_exists($this, 'getRelatedCacheTags')) {
            $tags = array_merge($tags, (array) $this->getRelatedCacheTags());
        }

        return array_values(array_unique(array_filter($tags)));
    }

    /**
     * Resolve cache tags for declared parent relations.
     * Generates both granular child tags ("stores:5:orders") and parent umbrella tags ("stores:5").
     */
    protected function resolveParentRelationCacheTags(): array
    {
        $relations = $this->resolveConfiguredParentRelations();
        if (empty($relations)) {
            return [];
        }

        $tags = [];
        $childTable = $this->getTable();

        foreach ($relations as $relationName) {
            if (!is_string($relationName) || trim($relationName) === '') {
                continue;
            }

            $relationName = trim($relationName);

            // Guard against missing relation methods
            if (!method_exists($this, $relationName)) {
                continue;
            }

            try {
                // Safeguard: Check that the method does not require parameters (e.g. store($type))
                $refMethod = new \ReflectionMethod($this, $relationName);
                if ($refMethod->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                $relation = $this->{$relationName}();

                // Safeguard: Must be BelongsTo, strictly excluding polymorphic MorphTo relations
                if (!($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo)
                    || ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo)) {
                    continue;
                }

                $foreignKey = $relation->getForeignKeyName();
                $parentModel = $relation->getRelated();
                $parentTable = $parentModel->getTable();

                // Safeguard: Handle both current and original foreign keys in case the parent changed (e.g. store 1 -> store 2)
                $currentParentId = $this->getAttribute($foreignKey);
                $originalParentId = $this->getOriginal($foreignKey);

                $parentIds = array_unique(array_filter([$currentParentId, $originalParentId], function ($id) {
                    return $id !== null && $id !== '';
                }));

                foreach ($parentIds as $parentId) {
                    // 1. Scoped collection tag: "stores:5:orders"
                    $tags[] = "{$parentTable}:{$parentId}:{$childTable}";

                    // 2. Parent umbrella tag: "stores:5"
                    $tags[] = "{$parentTable}:{$parentId}";
                }
            } catch (\Throwable) {
                // Never allow a cache resolution failure to break core application transactions
                continue;
            }
        }

        return $tags;
    }

    /**
     * Resolve configured parent relation names supporting all three developer conventions:
     * - $cacheTouches = ['store']   (Laravel $touches muscle memory)
     * - $clearsRelations = ['store'] (Expressive clean English)
     * - $cacheParents = ['store']   (Standard package convention)
     */
    protected function resolveConfiguredParentRelations(): array
    {
        foreach (['cacheTouches', 'clearsRelations', 'cacheParents'] as $prop) {
            if (property_exists($this, $prop) && is_array($this->{$prop})) {
                return $this->{$prop};
            }
        }

        return [];
    }
}
