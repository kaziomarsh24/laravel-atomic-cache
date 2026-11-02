# Troubleshooting & FAQ

### 1. The cache is not cleared after updating data

| Possible Cause | Recommended Fix |
| :--- | :--- |
| **Mass Eloquent Mutations:** `Model::where()->update()`, `delete()`, `insert()`, or `DB::table()`. These bypass Eloquent model events. | Call `Product::flushCache();` or `AtomicCache::clearCollection('products')` right after the bulk query. |
| **Missing Trait:** Model does not use `AutoClearsCache`. | Add `use AutoClearsCache;` to the model. Run `php artisan atomic-cache:check` to audit your models. |
| **Quiet Operations:** Using `saveQuietly()`, `updateQuietly()`, or `withoutEvents()`. | These suppress model events by design. Call `$product->clearCache()` manually. |
| **Pivot Table Changes:** Many-to-many `attach()`, `detach()`, or `sync()`. | Pivot mutations do not fire parent model events. Call `AtomicCache::clearTags(...)` on the related models after syncing. |
| **Rolled-back Transactions:** The write ran inside a `DB::transaction(...)` that rolled back. | `AutoClearsCache` uses `DB::afterCommit`. If the transaction rolls back, no database changes occurred, so cache remains intact. |
| **Custom Owner Keys:** A child model uses a custom owner key (e.g. `belongsTo(Store::class, 'slug', 'slug')`). | `$cacheParents` automatically skips custom owner keys to prevent mismatched umbrella tags. Invalidate parent tags manually. |

---

### 2. The cache never hits (always a miss)

| Possible Cause | Recommended Fix |
| :--- | :--- |
| **Caching Disabled:** `ATOMIC_CACHE_ENABLED=false` in `.env`. | Enable it in your environment: `ATOMIC_CACHE_ENABLED=true`. |
| **Query Parameter Limits Exceeded:** The request has more than 20 query parameters or query string exceeds 2048 bytes. | AtomicCache bypasses caching on abnormal queries to protect against Cache Poisoning DoS attacks. Adjust `ATOMIC_CACHE_QUERY_MAX_PARAMS` or `ATOMIC_CACHE_QUERY_MAX_BYTES` if legitimate requests are large. |
| **OCC Discard on High Writes:** Tags were invalidated while the `remember()` closure was computing. | Expected behavior under Optimistic Concurrency Control. On write-heavy tables, use targeted single-record keys instead of broad table tags. |
| **Computed TTL <= 0:** TTL is 0, negative, or in the past. | AtomicCache forgets/clears keys when given a non-positive TTL or past `DateTimeInterface`. Verify your TTL logic. |

---

### 3. Common Architecture Questions

#### "Tags are flushed but keys remain in Redis on Laravel 10"
On Laravel 10 combined with `phpredis` 6.1 or newer, native Laravel Redis tag clearing could fail to scan tag entries on `flush()`. Atomic Cache includes an automatic direct tag purge mechanism for this combination. In high-write production environments on Redis, also schedule Laravel's native tag pruning command:

```php
// In routes/console.php or app/Console/Kernel.php:
Schedule::command('cache:prune-stale-tags')->hourly();
```

#### "atomic-cache:clear --all cleared my sessions and queues"
Using `--all` flushes the **entire** configured cache store via `flush()`. If your cache store is shared with sessions or queue jobs, invalidate specific tags (e.g. `php artisan atomic-cache:clear --tags=products`). To isolate application caches from sessions, configure a dedicated cache store via `ATOMIC_CACHE_STORE`.

#### "Multi-Tenant applications sharing cache"
In multi-tenant setups, models with similar IDs across tenants (e.g. Tenant A Order #5 and Tenant B Order #5) will resolve identical tags (`orders:5`). To prevent cross-tenant collisions, configure a dynamic tenant-specific prefix in `config('atomic-cache.prefix')` during tenant identification.

::: info Note on Native Redis Tags
When using Redis native tags (`TaggedCache`), Laravel's underlying tag set key (`tag:orders:entries`) is not prefixed by custom runtime prefixes unless the Redis cache store connection itself has a dedicated prefix. In single-Redis shared multi-tenant databases, invalidating `AtomicCache::clearTags(['orders'])` in Tenant A will also flush the native `orders` tag set in Tenant B (causing over-invalidation, though never data leakage). To achieve complete tag isolation across tenants in Redis, assign each tenant a dedicated Redis database or connection prefix.
:::
