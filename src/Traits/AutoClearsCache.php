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
            $model->clearModelCacheOnCommit(false);
        });

        static::updated(function ($model) {
            $model->clearModelCacheOnCommit(true);
        });

        static::deleted(function ($model) {
            $model->clearModelCacheOnCommit(false);
        });

        // Safely register 'restored' event only if the Model uses SoftDeletes
        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->clearModelCacheOnCommit(false);
            });
        }
    }

    /**
     * Clear caches after active database transaction commits on the model's actual connection.
     * Evaluates cache tags immediately so original/dirty attribute states (e.g. changed foreign keys)
     * are captured before finishSave() executes syncOriginal().
     */
    public function clearModelCacheOnCommit(bool $isUpdate = false): void
    {
        $tagsToClear = $this->resolveCacheTagsToClear($isUpdate);
        if (empty($tagsToClear)) {
            return;
        }

        $callback = function () use ($tagsToClear) {
            AtomicCache::clearTags($tagsToClear);
        };

        $connection = $this->getConnection();

        if ($connection && method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);
        } else {
            $callback();
        }
    }

    /**
     * Clear caches for this model instance (Expressive alias for clearModelCacheOnCommit).
     */
    public function clearCache(): void
    {
        $this->clearModelCacheOnCommit();
    }

    /**
     * Statically flush all collection and table-level caches for this model.
     * Ideal for manual invalidation after bulk updates, mass deletes, or direct DB queries.
     */
    public static function flushCache(): bool
    {
        $instance = new static();
        return AtomicCache::clearCollection($instance->getTable());
    }

    /**
     * Resolve all cache tags that should be invalidated for this model instance.
     */
    public function resolveCacheTagsToClear(bool $isUpdate = false): array
    {
        $table = $this->getTable();
        $id = $this->getKey();

        $tags = [];

        // 1. Invalidate single item tag: "users:12" (only if key is present)
        if ($id !== null && $id !== '') {
            $tags[] = "{$table}:{$id}";
        }

        // Optimization: If this is an update and only ignored columns changed, skip collection and parent invalidation
        if ($isUpdate && $this->onlyIgnoredColumnsChanged()) {
            return array_values(array_unique(array_filter($tags)));
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

    /**
     * Get list of columns that should not trigger collection-level cache clearing on update.
     */
    public function getCacheIgnoreColumns(): array
    {
        if (property_exists($this, 'cacheIgnoreColumns')) {
            try {
                $ref = new \ReflectionProperty($this, 'cacheIgnoreColumns');
                if ($ref->isInitialized($this)) {
                    $val = $ref->getValue($this);
                    if (is_array($val)) {
                        return $val;
                    }
                    if (is_string($val) && trim($val) !== '') {
                        return [trim($val)];
                    }
                }
            } catch (\Throwable) {
                return [];
            }
        }

        return [];
    }

    /**
     * Determine if the update only affected ignored cache columns (and the updated_at timestamp).
     */
    public function onlyIgnoredColumnsChanged(): bool
    {
        $ignored = $this->getCacheIgnoreColumns();
        if (empty($ignored)) {
            return false;
        }

        $changes = array_keys($this->getChanges());
        if (empty($changes)) {
            return false;
        }

        $updatedAtCol = $this->usesTimestamps() ? $this->getUpdatedAtColumn() : null;

        $significantChanges = array_filter($changes, function ($col) use ($updatedAtCol, $ignored) {
            if ($updatedAtCol !== null && $col === $updatedAtCol) {
                return false;
            }
            return !in_array($col, $ignored, true);
        });

        return empty($significantChanges);
    }

    protected function resolveParentRelationCacheTags(): array
    {
        $relations = $this->resolveConfiguredParentRelations();
        if (empty($relations)) {
            return [];
        }

        $this->validateDeclaredParentRelations($relations);

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

                $parentModel = $relation->getRelated();
                $parentTable = $parentModel->getTable();

                // Safeguard: Custom owner key guard (e.g. belongsTo(Store::class, 'store_slug', 'slug'))
                // The owner key must match the parent model's primary key name, otherwise parent umbrella
                // tags would mismatch (e.g. parent clears 'stores:5' but child generated 'stores:nike').
                if ($relation->getOwnerKeyName() !== $parentModel->getKeyName()) {
                    continue;
                }

                $foreignKey = $relation->getForeignKeyName();

                // Safeguard: Handle both current and original foreign keys in case the parent changed (e.g. store 1 -> store 2)
                $currentParentId = $this->getAttribute($foreignKey);
                $originalParentId = $this->getOriginal($foreignKey);

                // Unwrap BackedEnums and Stringable objects to scalar values before filtering & array_unique
                // This prevents fatal errors in array_unique (which uses SORT_STRING by default)
                $rawParentIds = array_map(function ($id) {
                    if ($id instanceof \BackedEnum) {
                        return $id->value;
                    }
                    if (is_object($id) && method_exists($id, '__toString')) {
                        return (string) $id;
                    }
                    return $id;
                }, [$currentParentId, $originalParentId]);

                $parentIds = array_values(array_unique(array_filter($rawParentIds, function ($id) {
                    return is_scalar($id) && trim((string) $id) !== '';
                })));

                foreach ($parentIds as $parentId) {
                    $parentId = trim((string) $parentId);

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
     * In-memory class-level cache for configured parent relations to eliminate reflection overhead on repeated saves.
     */
    protected static array $configuredParentRelationsCache = [];

    /**
     * In-memory cache of validated model classes to avoid repeating reflection checks.
     */
    protected static array $validatedParentRelationModels = [];

    /**
     * Resolve configured parent relation names supporting all three developer conventions:
     * - $cacheParents = ['store']   (Standard package convention - Recommended)
     * - $cacheTouches = ['store']   (Laravel $touches style alias)
     * - $clearsRelations = ['store'] (Expressive clean English alias)
     */

    /**
     * Determine if parent relation strict mode is enabled.
     */
    protected static function isParentRelationStrictModeEnabled(): bool
    {
        $strict = config('atomic-cache.strict_mode');

        if (is_bool($strict)) {
            return $strict;
        }

        if (function_exists('app') && app()->isLocal()) {
            return true;
        }

        return false;
    }

    /**
     * Validate declared parent relations for this model.
     * Throws \InvalidArgumentException in strict mode if any relation definition is invalid.
     */
    protected function validateDeclaredParentRelations(array $relations): void
    {
        $class = static::class;
        if (isset(static::$validatedParentRelationModels[$class])) {
            return;
        }

        $isStrict = static::isParentRelationStrictModeEnabled();

        foreach ($relations as $relationName) {
            if (!is_string($relationName) || trim($relationName) === '') {
                continue;
            }

            $relationName = trim($relationName);

            if (!method_exists($this, $relationName)) {
                if ($isStrict) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] declared cache parent relation ['{$relationName}'], but method {$relationName}() does not exist on the model."
                    );
                }
                \Illuminate\Support\Facades\Log::warning(
                    "[AtomicCache] Model [{$class}] declared cache parent relation ['{$relationName}'], but method {$relationName}() does not exist."
                );
                continue;
            }

            $refMethod = new \ReflectionMethod($this, $relationName);
            if ($refMethod->getNumberOfRequiredParameters() > 0) {
                if ($isStrict) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] declared cache parent relation ['{$relationName}'], but method {$relationName}() requires parameters."
                    );
                }
                \Illuminate\Support\Facades\Log::warning(
                    "[AtomicCache] Model [{$class}] declared cache parent relation ['{$relationName}'], but method requires parameters."
                );
                continue;
            }

            try {
                $relation = $this->{$relationName}();

                if (!($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo)
                    || ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo)) {
                    if ($isStrict) {
                        throw new \InvalidArgumentException(
                            "Model [{$class}] declared cache parent relation ['{$relationName}'], but relation is not an instance of BelongsTo."
                        );
                    }
                    \Illuminate\Support\Facades\Log::warning(
                        "[AtomicCache] Model [{$class}] declared cache parent relation ['{$relationName}'], but it is not a BelongsTo relation."
                    );
                    continue;
                }

                $parentModel = $relation->getRelated();
                if ($relation->getOwnerKeyName() !== $parentModel->getKeyName()) {
                    if ($isStrict) {
                        throw new \InvalidArgumentException(
                            "Model [{$class}] declared cache parent relation ['{$relationName}'] with custom owner key ['" . $relation->getOwnerKeyName() . "'] that does not match primary key ['" . $parentModel->getKeyName() . "']."
                        );
                    }
                    \Illuminate\Support\Facades\Log::warning(
                        "[AtomicCache] Model [{$class}] declared cache parent relation ['{$relationName}'] with non-primary owner key."
                    );
                }
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable) {
                continue;
            }
        }

        static::$validatedParentRelationModels[$class] = true;
    }

    protected function resolveConfiguredParentRelations(): array
    {
        $class = static::class;

        if (isset(static::$configuredParentRelationsCache[$class])) {
            return static::$configuredParentRelationsCache[$class];
        }

        $relations = [];

        foreach (['cacheParents', 'cacheTouches', 'clearsRelations'] as $prop) {
            try {
                // Check property_exists and reflection isInitialized to avoid triggering Eloquent magic __isset()
                if (property_exists($this, $prop)) {
                    $ref = new \ReflectionProperty($this, $prop);
                    if ($ref->isInitialized($this)) {
                        $val = $ref->getValue($this);
                        if (is_array($val)) {
                            $relations = $val;
                            break;
                        }
                        if (is_string($val) && trim($val) !== '') {
                            $relations = [trim($val)];
                            break;
                        }
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return static::$configuredParentRelationsCache[$class] = $relations;
    }

    /**
     * Flush in-memory configured parent relations cache (useful for testing and Octane resets).
     */
    public static function flushConfiguredParentRelationsCache(): void
    {
        static::$configuredParentRelationsCache = [];
        static::$validatedParentRelationModels = [];
    }
}
