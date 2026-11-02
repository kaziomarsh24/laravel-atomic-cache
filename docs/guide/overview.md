# Overview & Architectural Philosophy

Laravel Atomic Cache is a stampede-proof caching and invalidation engine for Laravel **10.x, 11.x, 12.x, and 13.x**. It solves four common caching problems:

1. **Cache Stampedes:** Concurrent traffic spikes overwhelming databases when cached keys expire.
2. **Virtual Tagging on Non-Redis Stores:** Native Laravel crashes when calling `Cache::tags()` on File or Database drivers.
3. **Dirty Reads on Database Rollbacks:** Model observers caching uncommitted records that later roll back.
4. **Memory Exhaustion:** Large collections freezing Redis single-threaded loops or breaching Memcached 1MB key limits.

---

## Architectural Philosophy: Core-First Caching

Laravel Atomic Cache is organized into a clean **Core-First architecture**:

```text
┌────────────────────────────────────────────────────────────────────────┐
│                      Optional Syntactic Sugar                          │
│   Query Macros           Service Trait            Blade Directives     │
│  (->atomicCache())     (HasAtomicCache)           (@atomicCache)       │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
┌───────────────────────────────────▼────────────────────────────────────┐
│                  Eloquent Model Lifecycle Layer                        │
│                         (AutoClearsCache)                              │
│         created / updated / deleted / restored  --> DB::afterCommit     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
┌───────────────────────────────────▼────────────────────────────────────┐
│                   The Core Engine (AtomicCache::)                      │
│   Distributed Locks  •  Virtual Tags  •  Gzip Compression  •  OCC      │
│   Transparent Chunking  •  Self-Healing  •  TTL Jitter  •  Cache DNA   │
└────────────────────────────────────────────────────────────────────────┘
```

The **Core Engine (`AtomicCache::`)** is completely self-contained and explicit. While query macros and traits provide convenient shortcuts, you can build your entire application directly with the Core Engine for transparent and predictable control.

---

## Guarantees vs Non-Guarantees

To ensure complete clarity on what the package handles automatically versus what requires developer attention:

| Scenario / Operation | Automated by Package? | Behavior / Action Required |
| :--- | :--- | :--- |
| **Eloquent Model Instance Save:** `$product->save()`, `update()` | Yes | Automatically flushes item tag (`products:42`), collection tag (`products`), and parent relations strictly on `DB::afterCommit`. |
| **Model Deletes & Restores:** `$product->delete()`, `restore()` | Yes | Automatically flushes related tags on `DB::afterCommit`. |
| **Model Counters:** `$product->increment()`, `decrement()` | Yes | Fires `updated` model event; automatically clears cache on commit. |
| **Single-Level BelongsTo Parents:** `$cacheParents = ['store']` | Yes | Automatically clears child tag, scoped tag (`stores:5:orders`), and parent umbrella tag (`stores:5`). |
| **Rolled-back Transactions:** `DB::rollBack()` | Yes | Invalidation callbacks are deferred to `afterCommit`. If rollback occurs, cache is untouched. |
| **Query Builder Mass Updates:** `Product::where(...)->update(...)` | No | Eloquent events do not fire. You must manually call `Product::flushCache();` or `AtomicCache::clearCollection('products')`. |
| **Query Builder Mass Deletes:** `Product::where(...)->delete(...)` | No | Eloquent events do not fire. Call `Product::flushCache();` manually. |
| **Direct SQL / DB Facade:** `DB::table('products')->delete(...)` | No | Bypasses Eloquent entirely. Call `AtomicCache::clearCollection('products')` manually. |
| **Quiet Operations:** `saveQuietly()`, `withoutEvents()` | No | Intentionally suppresses model events. Call `$product->clearCache()` manually if needed. |
| **Pivot Table Changes:** Many-to-many `attach()`, `detach()`, `sync()` | No | Pivot mutations do not fire model events on parent models. Call `AtomicCache::clearTags(...)` manually. |
| **Multi-Level Grandparent Propagation:** `Item -> Order -> Store` | No | Laravel's native `$touches` property calls `rawUpdate()` which **does not** fire Eloquent model events. You must either manually invoke `$orderItem->order->touch()` in an observer/controller or declare explicit parent tags via `getRelatedCacheTags()`. |
| **External Database Mutations:** External scripts, DB triggers, ETL imports | No | External processes outside PHP cannot trigger Laravel events. Schedule or invoke cache invalidation manually. |
