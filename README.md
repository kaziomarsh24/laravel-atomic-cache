# Laravel Atomic Cache

[![Run Tests](https://github.com/kaziomarsh24/laravel-atomic-cache/actions/workflows/run-tests.yml/badge.svg)](https://github.com/kaziomarsh24/laravel-atomic-cache/actions)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20to%208.5-777BB4.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)

> ### High-Performance Caching & Invalidation Engine for Laravel
> Eliminates cache stampedes using atomic distributed locks, auto-compresses large payloads (>50KB) by up to 70–80% (depending on payload structure), auto-partitions mega datasets across driver-safe chunks, brings zero-crash virtual tagging to File and Database drivers, and defers model cache invalidation to database transaction commits (`DB::afterCommit`).

```php
use App\Models\Product;
use KaziOmar\AtomicCache\Facades\AtomicCache;

// 1. Stampede Protection: 1,000 concurrent requests only run 1 query; others wait & read
$report = AtomicCache::rememberWithLock('monthly_report', 3600, fn() => Report::generate());

// 2. Transaction-Safe Zero-Key Query Caching: auto-fingerprints SQL & auto-clears on commit
$featured = Product::where('status', 'active')->latest()->atomicCache(3600);
```

---

## Table of Contents

- [Why Laravel Atomic Cache?](#why-laravel-atomic-cache)
- [Requirements & Installation](#requirements--installation)
- [Quickstart in 10 Seconds](#quickstart-in-10-seconds)
- [Architectural Philosophy: Core-First Caching](#architectural-philosophy-core-first-caching)
- [Guarantees vs Non-Guarantees](#guarantees-vs-non-guarantees)
- [Layer 1: The Core Caching Engine (`AtomicCache::`)](#layer-1-the-core-caching-engine-atomiccache)
  - [1.1 Basic Caching](#11-basic-caching)
  - [1.2 Stampede Protection (`rememberWithLock`)](#12-stampede-protection-rememberwithlock)
  - [1.3 Virtual Tagging Engine (Native & Non-Taggable Stores)](#13-virtual-tagging-engine-native--non-taggable-stores)
  - [1.4 Manual Cache Invalidation Methods](#14-manual-cache-invalidation-methods)
  - [1.5 Automatic Payload Compression (Gzip)](#15-automatic-payload-compression-gzip)
  - [1.6 Transparent Chunking (Mega Datasets & Collections)](#16-transparent-chunking-mega-datasets--collections)
  - [1.7 Cache TTL Jitter (Thundering Herd Variance)](#17-cache-ttl-jitter-thundering-herd-variance)
  - [1.8 User-Scoped Caching (`rememberForUser`)](#18-user-scoped-caching-rememberforuser)
  - [1.9 Write Deduplication (Cache DNA)](#19-write-deduplication-cache-dna)
  - [1.10 Optimistic Concurrency Control (OCC)](#110-optimistic-concurrency-control-occ)
  - [1.11 Self-Healing Engine](#111-self-healing-engine)
- [Layer 2: Eloquent Model Lifecycle (`AutoClearsCache`)](#layer-2-eloquent-model-lifecycle-autoclearscache)
  - [2.1 Automatic Invalidation on `DB::afterCommit`](#21-automatic-invalidation-on-dbaftercommit)
  - [2.2 Declarative Parent Invalidation (`$cacheParents`)](#22-declarative-parent-invalidation-cacheparents)
  - [2.3 Model-Level Manual Cache Flushing (`flushCache` & `clearCache`)](#23-model-level-manual-cache-flushing-flushcache--clearcache)
  - [2.4 Critical Rules & What Breaks Model Auto-Invalidation](#24-critical-rules--what-breaks-model-auto-invalidation)
- [Layer 3: Query Builder Macros (`->atomicCache()`)](#layer-3-query-builder-macros-atomiccache)
  - [3.1 Zero-Key Query & First Record Caching](#31-zero-key-query--first-record-caching)
  - [3.2 Critical Rules & What Breaks Query Macros](#32-critical-rules--what-breaks-query-macros)
- [Layer 4: Service & Repository Layer (`HasAtomicCache`)](#layer-4-service--repository-layer-hasatomiccache)
  - [4.1 Automatic Pagination & Filter Isolation (`rememberCollection`)](#41-automatic-pagination--filter-isolation-remembercollection)
  - [4.2 Single Item Caching (`rememberItem`)](#42-single-item-caching-rememberitem)
  - [4.3 Query Parameter Sanitization & Whitelisting](#43-query-parameter-sanitization--whitelisting)
  - [4.4 DoS & Cache Poisoning Limits](#44-dos--cache-poisoning-limits)
  - [4.5 Critical Rules & What Breaks Service Caching](#45-critical-rules--what-breaks-service-caching)
- [Layer 5: Blade Fragment Caching (`@atomicCache`)](#layer-5-blade-fragment-caching-atomiccache)
  - [5.1 Usage & Tagging](#51-usage--tagging)
  - [5.2 Critical Rules & What Breaks Blade Caching](#52-critical-rules--what-breaks-blade-caching)
- [Layer 6: CLI Diagnostics & Auditing](#layer-6-cli-diagnostics--auditing)
  - [6.1 Model Architecture Auditor (`atomic-cache:check`)](#61-model-architecture-auditor-atomic-cachecheck)
  - [6.2 Performance Benchmark CLI (`atomic-cache:bench`)](#62-performance-benchmark-cli-atomic-cachebench)
  - [6.3 Safe Cache Clearing CLI (`atomic-cache:clear`)](#63-safe-cache-clearing-cli-atomic-cacheclear)
- [Driver Compatibility Matrix](#driver-compatibility-matrix)
- [Configuration Reference](#configuration-reference)
- [Troubleshooting & FAQ](#troubleshooting--faq)
- [Upgrade Guide](#upgrade-guide)
- [Testing](#testing)
- [Credits & License](#credits--license)

---

## Why Laravel Atomic Cache?

| Feature | Standard Laravel Cache | Laravel Atomic Cache |
| :--- | :--- | :--- |
| **Stampede Protection** | No (All concurrent requests hit DB) | **Atomic Lock** (1 worker computes, others wait & read) |
| **Large Payload Size** | Raw uncompressed serialization | **Auto Gzip** (>50KB cuts Redis RAM by up to 70–80%) |
| **Mega Datasets** | Fails on 1MB Memcached / Redis freeze | **Transparent Chunking** (splits safely, preserves keys) |
| **Redundant Writes** | Every put hits storage/network | **Write Deduplication** (skips re-compression & chunk re-splitting) |
| **Driver Tag Support** | Crashes on File/Database stores | **Virtual Tagging** (works across all stores without Redis) |
| **Corrupted Data** | Throws crash exception to user | **Self-Healing** (cleans key, regenerates silently) |
| **Simultaneous Expiry** | All keys expire in same second | **TTL Jitter** (random variance prevents herd spikes) |
| **Model Invalidation** | Fragile observers before commit | **DB::afterCommit** (prevents transaction race conditions) |
| **Query Caching** | Manual key hashing required | **Zero-Key Macros** (auto SQL + eager load fingerprinting) |
| **Architecture Audit** | Manual DIY logging | **Artisan Doctor CLI** (`atomic-cache:check`) |

---

## Requirements & Installation

- **PHP:** `8.2`, `8.3`, `8.4`, or `8.5`
- **Laravel Framework:** `10.x`, `11.x`, `12.x`, or `13.x`
- **PHP Extensions:** `ext-zlib` (optional for Gzip compression, enabled by default in 99% of PHP installs)

Install via Composer:

```bash
composer require thekaziomar/laravel-atomic-cache
```

Publish configuration (optional):

```bash
php artisan vendor:publish --tag="atomic-cache-config"
```

---

## Quickstart in 10 Seconds

You can use Atomic Cache in two ways: via the **Core Facade** for explicit control, or via **Model & Query Macros** for declarative automation.

### Approach A: Explicit Core Caching (Recommended)
```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

// Tagged, stampede-proof caching across any store:
$catalog = AtomicCache::tags(['products'])->rememberWithLock('active_products', 3600, function () {
    return Product::where('status', 'active')->get();
});
```

### Approach B: Declarative Model & Query Automation
1. **In your Model:** Add `use AutoClearsCache;`
   ```php
   namespace App\Models;
   use Illuminate\Database\Eloquent\Model;
   use KaziOmar\AtomicCache\Traits\AutoClearsCache;

   class Product extends Model
   {
       use AutoClearsCache;
   }
   ```
2. **In your Query:** Chain `->atomicCache()`
   ```php
   $products = Product::where('status', 'active')->latest()->atomicCache(3600);
   $product = Product::where('slug', $slug)->atomicCacheFirst(3600);
   ```
3. **Audit your models:**
   ```bash
   php artisan atomic-cache:check
   ```

---

## Architectural Philosophy: Core-First Caching

Laravel Atomic Cache is organized into a clean **Core-First architecture**:

```
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

---

## Layer 1: The Core Caching Engine (`AtomicCache::`)

The `AtomicCache` facade provides manual, high-performance caching with distributed concurrency controls.

### 1.1 Basic Caching

#### How it works:
Works intuitively like native Laravel cache, supporting flexible parameter ordering (both `$ttl, $callback` and `$callback, $ttl`):

```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

// Standard signature: remember($key, $ttl, $callback)
$posts = AtomicCache::remember('posts.all', 3600, function () {
    return Post::where('published', true)->get();
});

// Alternative signature: remember($key, $callback, $ttl)
$posts = AtomicCache::remember('posts.all', fn() => Post::all(), 3600);

// Global helper shortcut:
$posts = atomic_cache('posts.all', fn() => Post::all(), 3600);

// Standard CRUD operations:
AtomicCache::put('site_settings', $settings, 86400); // Store directly
$value = AtomicCache::get('site_settings');          // Retrieve
$exists = AtomicCache::has('site_settings');         // Check existence
$val = AtomicCache::pull('site_settings');           // Retrieve and delete
AtomicCache::forget('site_settings');                // Delete single key
```

#### Parameters Reference:
| Parameter | Type | Description |
| :--- | :--- | :--- |
| **`$key`** | `string` | Unique cache identifier in your cache store (e.g. `'posts.all'`, `'home.banners'`). |
| **`$ttl`** | `int\|DateTimeInterface` | Time-to-live in seconds or future Carbon instance. If omitted, uses `config('atomic-cache.default_ttl', 3600)`. |
| **`$callback`** | `Closure` | Closure executed on cache miss to compute the value. |

#### What will break it / What causes it not to work properly:
- **Passing a non-positive TTL (`0` or negative number):** Calling `AtomicCache::put('key', $value, 0)` will **not** cache the item; it immediately executes `forget('key')` and deletes the item.
- **Passing a past `DateTimeInterface`:** Passing a Carbon date in the past will calculate a negative TTL and immediately delete the key.
- **Un-serializable callback returns:** If your closure returns an open resource (like a `curl` handle, file pointer, or `PDO` instance), PHP serialization will fail and store an empty or corrupted entry.

---

### 1.2 Stampede Protection (`rememberWithLock`)

#### How it works:
When an expensive cache item expires under high traffic, dozens or hundreds of concurrent requests simultaneously execute the heavy database query (known as the **Dogpiling** or **Cache Stampede** effect).

`rememberWithLock` acquires an atomic distributed lock via Laravel's `LockProvider`. **Only the first process calculates the value**, while all other requests wait up to `waitSeconds` (default 5s) and read the freshly computed cache from memory using **Double-Checked Locking**:

```php
$stats = AtomicCache::rememberWithLock('annual_sales_report', 86400, function () {
    return HeavyReportingService::calculate();
}, lockSeconds: 15, waitSeconds: 5);
```

#### What will break it / What causes it not to work properly:
- **Using a cache store without lock support:** Drivers that do not implement `Illuminate\Contracts\Cache\LockProvider` (or the `null` driver whose `NullStore` returns a dummy `NoLock`) do not provide distributed concurrency locking. The engine safely detects unsupported lock providers and bypasses locking, executing the callback directly. *(Note: Laravel's `redis`, `memcached`, `database`, and `file` drivers all support locks natively).*
- **Calculation exceeding `lockSeconds`:** If your computation takes 20 seconds but `lockSeconds` is set to 10 seconds, the lock will expire prematurely while the first worker is still running, allowing waiting workers to enter and recompute. Always set `lockSeconds` higher than your maximum expected computation time.
- **`waitSeconds` too short:** If computation takes 4 seconds but `waitSeconds` is set to 1 second, waiting requests will time out waiting for the lock and fall back to executing the query themselves.

---

### 1.3 Virtual Tagging Engine (Native & Non-Taggable Stores)

#### How it works:
In standard Laravel, calling `Cache::tags(['products'])` on `file` or `database` cache drivers crashes with a fatal exception: `This cache store does not support tagging.`

Atomic Cache introduces a **Virtual Tag Versioning Engine**:
- On **Redis / Memcached / Array**: Uses native driver tags for high-speed tagging.
- On **File / Database / SQLite**: Embeds atomic UUID version tokens into cache keys (`{prefix}:{key}:vt:{hash}`).
- When `AtomicCache::clearTags(['products'])` is called, it replaces the virtual tag UUID in 0ms. All cached entries under that tag instantly experience a cache miss without requiring slow file directory traversals or heavy SQL deletes.

```php
// Tagging reads and writes (works identically on Redis, File, Database, or Array):
$catalog = AtomicCache::tags(['products', 'categories'])
    ->remember('catalog_active', 3600, fn() => Product::active()->get());

// Flushing all items under one or more tags:
AtomicCache::clearTags(['products']);
// Alias matching Laravel nomenclature:
AtomicCache::flushTags(['products', 'categories']);
```

#### What will break it / What causes it not to work properly:
- **Calling Laravel's native `Cache::tags()` on non-taggable stores:** Remember to always call `AtomicCache::tags()`. Calling Laravel's built-in `Cache::tags()` directly will still crash on `file` or `database` drivers.
- **File driver disk accumulation:** On the `file` driver, virtual tags invalidate entries logically in 0ms (subsequent reads miss immediately). However, the old serialized files remain on disk until their TTL expires or until `php artisan cache:clear` is run.

---

### 1.4 Manual Cache Invalidation Methods

#### How it works:
When performing operations that do not trigger automated model events (such as bulk updates or direct SQL queries), use these manual invalidation methods:

```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

// 1. Invalidate an entire collection / resource (flushes tag "products"):
AtomicCache::clearCollection('products');
AtomicCache::flushCollection('products');

// 2. Invalidate a single resource record (flushes tag "products:42"):
AtomicCache::clearItem('products', 42);
AtomicCache::forgetItem('products', 42);

// 3. Invalidate specific tags:
AtomicCache::clearTags(['products', 'stores:5']);
AtomicCache::flushTags(['homepage_featured']);

// 4. Invalidate an explicit key:
AtomicCache::forget('my_custom_cache_key');
```

#### What will break it / What causes it not to work properly:
- **Calling `AtomicCache::flush()` on a shared cache store:** Calling `AtomicCache::flush()` without active tags executes `flush()` on the underlying raw cache store. If your Redis store is shared with session or queue data, this will wipe user sessions! Always use `clearTags()` or `clearCollection()` instead of `flush()`.

---

### 1.5 Automatic Payload Compression (Gzip)

#### How it works:
Large Eloquent collections or API responses consume megabytes of Redis/Memcached RAM. Whenever a cached payload exceeds **50 KB** (configurable), Atomic Cache automatically compresses it with `gzcompress`:
- Reduces Redis/Memcached memory footprint by **up to 70% to 80%** (actual savings depend on payload structure and entropy).
- Decompression is completely transparent upon retrieval.
- Payloads smaller than 50 KB are stored raw without compression CPU overhead.

#### What will break it / What causes it not to work properly:
- **Missing `ext-zlib` extension:** If PHP is compiled without the `zlib` extension, compression is automatically skipped and data is saved uncompressed without crashing your application.
- **High-entropy binary data:** Payloads that are already compressed (such as JPEG images, PDF files, or zip archives) cannot be compressed further; Gzip will provide negligible reduction for them.

---

### 1.6 Transparent Chunking (Mega Datasets & Collections)

#### How it works:
Storing 10,000+ items or multi-megabyte collections in a single cache entry freezes Redis single-threaded loops and violates Memcached's strict 1MB key limit.

Atomic Cache includes **Transparent Chunking**:
- Arrays and Collections exceeding 1,000 items (or 800 KB estimated payload) are automatically partitioned across driver-safe chunk keys (`{key}:__chunk__:{generation}:{i}`).
- **Generation UUIDs (Zero Torn Reads):** All chunks are published under a unique generation UUID before the manifest pointer updates atomically, preventing concurrent readers from reading partial/torn data.
- **Dynamic 800 KB Guard:** Dynamically calculates items per chunk so individual chunk payloads never exceed 800 KB, remaining Memcached-safe.
- **Key preservation:** Associative string keys and sparse numeric array indexes are preserved upon reassembly.
- **Collection restoration:** Instances of `Illuminate\Support\Collection` are seamlessly reconstituted.

```php
// Storing 20,000 items automatically splits into 20 safe chunks behind the scenes:
$data = AtomicCache::remember('large_export', 3600, fn() => ExportRecord::all());
```

#### What will break it / What causes it not to work properly:
- **Disabling chunking with Memcached:** If you set `ATOMIC_CACHE_CHUNKING_ENABLED=false` in `.env` and attempt to cache a collection larger than 1MB on Memcached, Memcached will throw a payload size exception.
- **External key manipulation:** Manually deleting an individual `{key}:__chunk__:...` key in Redis will corrupt the chunk set. Atomic Cache's self-healing layer will detect the missing chunk, log a warning, and recompute the original data safely.

---

### 1.7 Cache TTL Jitter (Thundering Herd Variance)

#### How it works:
If you cache 1,000 database items simultaneously with a fixed TTL of 3,600 seconds, all 1,000 keys expire at the exact same microsecond, causing an instant CPU spike (known as the **Thundering Herd** problem).

`withJitter()` adds a small random variance (e.g. ±5%) to the TTL:

```php
// TTL of 3600s will randomly vary between 3420s and 3780s:
AtomicCache::withJitter(0.05)->remember('popular_feed', 3600, fn() => ...);
```

#### What will break it / What causes it not to work properly:
- **Passing jitter percentages greater than 50%:** The engine clamps jitter between 0% and 50% to prevent non-positive TTL calculations from deleting keys.

---

### 1.8 User-Scoped Caching (`rememberForUser`)

#### How it works:
Scopes the cache key to the currently authenticated user ID (`atomic:user:{id}:{key}`) or an explicit user ID:

```php
// Automatically scopes to auth()->id():
$userMenu = AtomicCache::rememberForUser('user_menu', 3600, fn() => ...);

// Explicit user ID scoping:
$dashboard = AtomicCache::rememberForUser('dashboard', 3600, fn() => ..., userId: 42);
```

#### What will break it / What causes it not to work properly:
- **Unauthenticated guest users sharing session-less state:** If `rememberForUser()` is called when no user is logged in (`auth()->id() === null`), Atomic Cache falls back to the current session ID (`guest:{session_id}`). If sessions are disabled or uninitialized, it falls back to a global `guest` key. Do not use `rememberForUser` for guest-facing public content.

---

### 1.9 Write Deduplication (Cache DNA)

#### How it works:
When `ATOMIC_CACHE_DEDUPLICATION_ENABLED=true` is enabled in `.env`, Atomic Cache hashes the serialized payload (`Cache DNA`) before writing. If identical content is already cached with a valid remaining TTL, **it skips CPU-intensive Gzip re-compression and chunk re-splitting**, refreshing the existing TTL in storage.

#### What will break it / What causes it not to work properly:
- **Non-deterministic payloads:** If your cached array includes dynamic timestamps like `now()` or random tokens, every payload hash will differ, bypassing deduplication benefits.

---

### 1.10 Optimistic Concurrency Control (OCC)

#### How it works:
If a heavy calculation takes 4 seconds, and during second 2 an admin updates the database (flushing the tag), writing the 4-second-old result to the cache at second 4 would overwrite the update with stale data.

Atomic Cache dramatically minimizes stale cache writes using Optimistic Concurrency Control (OCC). It captures universal UUID version tokens per tag before computing the value. If a tag is cleared while a closure is executing, the token mismatch triggers an OCC discard, safely dropping the stale write instead of repopulating the cache with outdated data. Furthermore, virtual store writes embed the pre-computation version hash into the storage key, ensuring that even in race conditions, stale writes fall into orphaned keys that are never read.

#### What will break it / What causes it not to work properly:
- **Direct `Cache::tags([...])->flush()` bypass:** OCC relies on `AtomicCache::clearTags()`, `flushTags()`, or model events rotating the tag version token. If application code or a third-party package bypasses Atomic Cache and calls Laravel's native `Cache::tags([...])->flush()` directly, the native tags are flushed but Atomic Cache's version tokens are not rotated.
- **External non-PHP invalidation:** If an external service or raw Redis CLI command flushes Redis tags directly bypassing PHP, Atomic Cache cannot detect the external modification.
- **Redis `allkeys-lru` eviction:** OCC version tokens are stored with `forever()`. If Redis runs out of memory under an `allkeys-lru` policy, Redis may evict version token keys. If evicted, the next access generates a fresh UUID token. This safely forces a cache miss (preventing stale data), but can temporarily elevate cache miss rates until repopulated.
- **Write-heavy tables with broad tags:** On tables with continuous concurrent updates (e.g., streaming telemetry), OCC will repeatedly discard stale writes. For high-frequency write tables, cache targeted single-item keys instead of broad table collections.

---

### 1.11 Self-Healing Engine

#### How it works:
If a cached payload is corrupted, truncated by memory limits, or missing a chunk, Atomic Cache catches the deserialization exception, logs the event, purges the broken key, and silently recomputes fresh data from the callback without crashing the user's HTTP request.

---

### 1.12 Transaction Guard (Active Database Transaction Bypass)

#### How it works:
Running cache `remember()` inside an uncommitted database transaction (`DB::transaction(...)`) poses a serious dirty-read and phantom-cache risk: if the transaction rolls back, uncommitted data written to cache becomes a ghost entry that misleads users for hours.

When `bypass_during_transactions` is enabled (default: `true`), Atomic Cache detects active transactions across all opened database connections (`DB::transactionLevel() > 0`). In this state, `remember()` **bypasses reading and writing to cache entirely**, executing the closure directly and returning fresh database data. If the transaction rolls back, no phantom cache remains.

```php
DB::transaction(function () {
    $order = Order::create([...]);

    // Bypasses cache: returns fresh $order without writing to cache store
    $latest = AtomicCache::remember('latest_order', 3600, fn() => Order::latest()->first());

    if ($someError) {
        throw new \Exception("Rollback!"); // No phantom cache remains in Redis!
    }
});
```

To explicitly disable this bypass for a specific operation or testing:
```php
AtomicCache::withoutTransactionBypass()->remember('key', 3600, fn() => ...);
```

---

## Layer 2: Eloquent Model Lifecycle (`AutoClearsCache`)

Add `AutoClearsCache` to any Eloquent model to automate invalidations.

### 2.1 Automatic Invalidation on `DB::afterCommit`

#### How it works:
Hooks into Eloquent model events: `created`, `updated`, `deleted`, and `restored` (for SoftDeletes).

When a record changes:
1. Resolves item tag: `{table}:{id}` (e.g. `products:42`).
2. Resolves collection tag: `{table}` (e.g. `products`).
3. Resolves parent relation tags (if `$cacheParents` is declared).
4. Resolves custom related tags (if `$relatedCacheTags` is declared).
5. Defers clearing until **`$connection->afterCommit()`**. If a database transaction rolls back, your cache remains completely untouched!

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class Product extends Model
{
    use AutoClearsCache;

    // Optional: Invalidate additional related tags when any product changes:
    protected array $relatedCacheTags = ['landing_page_stats'];
}
```

---

### 2.2 Declarative Parent Invalidation (`$cacheParents`)

#### How it works:
Similar in spirit to Laravel's native `$touches` (operating on a single-level `BelongsTo` relation), you can declare parent relationships to automatically invalidate parent-scoped cache tags whenever a child record changes:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class Order extends Model
{
    use AutoClearsCache;

    // Automatically invalidates Store caches when an Order changes:
    protected array $cacheParents = ['store'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
```

When an order for Store #5 is created or updated:
- Flushes child item tag: `orders:{id}`
- Flushes child collection tag: `orders`
- Flushes parent scoped tag: `stores:5:orders`
- Flushes parent umbrella tag: `stores:5`
- **Dirty Foreign Key Handling:** If an order is moved from Store 1 to Store 2 (`$order->store_id = 2`), Atomic Cache invalidates tags for **both** Store 1 and Store 2!

> [!NOTE]
> **Parent Umbrella Tag Scope:** By design, `$cacheParents` flushes both the scoped collection tag (`stores:5:orders`) and the parent umbrella tag (`stores:5`). If your application caches the parent model's own record under `stores:5`, child writes will also refresh the parent's general cache.

---

### 2.3 Model-Level Manual Cache Flushing (`flushCache` & `clearCache`)

`AutoClearsCache` provides two convenient helper methods directly on the model:

```php
// 1. Instance helper: manually clear this record and its parent tags:
$product = Product::find(42);
$product->clearCache(); // Expressive alias for clearModelCacheOnCommit()

// 2. Static helper: flush all collection-level caches for this model's table:
Product::where('category_id', 5)->update(['discount' => 10]);
Product::flushCache(); // Wipes all "products" table collection caches in one line!
```

> [!IMPORTANT]
> **Important Scope Note:** `Product::flushCache()` invalidates the model's **table collection tag** (`products`). It does **not** invalidate individual item tags (`products:42`) or parent-scoped relation tags (`stores:5:orders`). If your model uses parent-scoped caching (`$cacheParents`), invalidate those tags explicitly via `AtomicCache::clearTags(['stores:5:orders', 'stores:5'])`.
>
> **Transaction Safety:** Unlike model lifecycle events which wait for `afterCommit`, `Product::flushCache()` executes immediately. If calling it inside an active `DB::transaction()`, wrap it in `DB::afterCommit(fn() => Product::flushCache())` to prevent concurrent readers from caching pre-committed data.

---

### 2.4 High-Frequency Update Columns (`$cacheIgnoreColumns`)

When updating counters or analytics (e.g. `view_count`, `last_login_at`, `login_count`), invalidating the entire table collection cache (`products`) or parent cache (`stores:1:products`) on every single page view causes severe cache churn.

Declare `$cacheIgnoreColumns` on your model to protect collections:
```php
class Product extends Model
{
    use AutoClearsCache;

    protected array $cacheParents = ['store'];

    // Only single-item cache ('products:42') is cleared when these change.
    // Heavy table collections ('products') and parent tags ('stores:1:products') remain cached:
    protected array $cacheIgnoreColumns = ['view_count', 'meta'];
}
```

> [!NOTE]
> **Automatic Timestamp Exclusion:** Laravel automatically dirties the `updated_at` column on save. Atomic Cache automatically ignores `updated_at` when evaluating `$cacheIgnoreColumns`.
>
> **Create & Delete Safety:** `$cacheIgnoreColumns` only applies to `updated` events. Model `created`, `deleted`, and `restored` events always invalidate collection tags regardless of this setting.

---

### 2.5 Strict Mode for Parent Relations (`strict_mode`)

Catch typos and invalid relationship declarations early during local development:
```php
// config/atomic-cache.php
'strict_mode' => env('ATOMIC_CACHE_STRICT_MODE', null), // null = active in 'local' and 'testing'
```

When strict mode is enabled, invalid declarations throw an immediate `\InvalidArgumentException` with clear guidance:
- Typo in method name (`$cacheParents = ['strore']`): throws missing method error.
- Method requiring parameters (`public function store($type)`): throws required parameter error.
- Non-`BelongsTo` relations (`hasMany`, `morphTo`): throws relation type error.

In production environments, strict mode safely logs a warning and gracefully continues without interrupting customer transactions.

---

### 2.6 Transaction-Safe Bulk Invalidation (`AtomicCache::invalidating`)

For bulk updates, mass deletes, or service workflows using direct DB queries, wrap execution with `AtomicCache::invalidating()`:

```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

// Outside transaction: flushes immediately
// Inside transaction: automatically defers invalidation to DB::afterCommit!
AtomicCache::invalidating([Order::class, Product::class], function () {
    DB::table('orders')->where('status', 'draft')->delete();
    DB::table('products')->where('stock', 0)->update(['status' => 'out_of_stock']);
});
```
If the transaction rolls back or the closure throws an exception, cache invalidation is cleanly aborted.

---

### 2.7 Critical Rules & What Breaks Model Auto-Invalidation

- **Mass queries bypass model events:** Direct query builder mutations (`Product::where(...)->update(...)`, `delete()`, or `DB::table('products')->update(...)`) **do not fire Eloquent events**. AutoClearsCache cannot detect them. 
  - *Solution:* Run `Product::flushCache();` right after your bulk query.
- **Quiet operations bypass events:** Calling `$model->saveQuietly()`, `updateQuietly()`, `deleteQuietly()`, or wrapping code in `Model::withoutEvents()` intentionally suppresses model events.
  - *Solution:* Manually call `$model->clearCache()` if you need invalidation after quiet saves.
- **Pivot table changes:** Many-to-many `attach()`, `detach()`, or `sync()` operations on relationships do not fire parent model events.
  - *Solution:* Call `AtomicCache::clearTags([Product::class, Category::class])` explicitly after syncing.
- **`$cacheParents` only supports `BelongsTo`:** Declaring `hasMany`, `hasOne`, `belongsToMany`, or `morphTo` in `$cacheParents` is not supported and will be silently ignored.
- **Custom owner keys are skipped:** If a relationship uses a custom non-primary owner key (e.g. `belongsTo(Store::class, 'store_slug', 'slug')`), it is skipped because the child model holds a string slug rather than the parent's primary ID, which would create mismatched umbrella tags (`stores:nike` vs `stores:5`).
- **Grandparent relations do not auto-cascade (`$touches` does not work):** Multi-level hierarchies (e.g. `OrderItem -> Order -> Store`) do not automatically cascade from item to store. **Crucially, Laravel's native `protected $touches = ['order']` property executes a raw database update (`rawUpdate`) and does NOT fire Eloquent model events.** Thus, declaring `$touches` will not trigger `AutoClearsCache` on the parent!
  - *Solution 1 (Recommended - Explicit Method):* Define `getRelatedCacheTags()` on the child model:
    ```php
    public function getRelatedCacheTags(): array
    {
        return [
            "stores:{$this->order->store_id}:orders",
            "stores:{$this->order->store_id}",
        ];
    }
    ```
  - *Solution 2 (Manual Touch):* Call `$orderItem->order->touch()` directly on the model instance (which calls `save()` and fires Eloquent's `updated` event).
- **Runtime property mutation is ignored:** Because configured parent relations are cached in-memory per class for performance, dynamically modifying `$model->cacheParents = [...]` on an active model instance at runtime will have no effect.
- **Octane / Long-Running Workers:** If you dynamically manipulate `$cacheParents` during testing or worker loops, clear the in-memory cache via `Model::flushConfiguredParentRelationsCache()`.
- **Rolled-back transactions do not clear cache:** If changes occur inside a `DB::transaction()` that throws an exception and rolls back, `afterCommit()` will not fire. The cache remains untouched.

---

## Layer 3: Query Builder Macros (`->atomicCache()`)

### 3.1 Zero-Key Query & First Record Caching

#### How it works:
Chain `->atomicCache()` directly off any Eloquent or Query Builder query:

```php
use App\Models\Product;
use Illuminate\Support\Facades\DB;

// 1. Auto-generates sha256 hash from SQL + bindings + eager loads
// Automatically tagged with 'products' and Product::class for auto-invalidation:
$featured = Product::where('status', 'active')->where('featured', 1)->atomicCache(3600);

// 2. Single record caching:
$product = Product::where('slug', $slug)->atomicCacheFirst(3600);

// 3. Custom key mode:
$bestSellers = Product::where('sales', '>', 500)->atomicCache('bestsellers', 7200);

// 4. Tenant & Parent-Scoped Queries (Complete Tenant Isolation):
// Omits the broad 'orders' table tag and tags strictly with the scoped tag:
$storeOrders = Order::where('store_id', 5)->atomicCache(scope: 'stores:5:orders');
$storeFirst = Order::where('store_id', 5)->latest()->atomicCacheFirst(scope: 'stores:5:orders');

// 5. Works on DB facade and relationships:
$stats = DB::table('orders')->where('status', 'paid')->atomicCache(1800);
$userOrders = $user->orders()->latest()->atomicCache(3600);
```

---

### 3.2 Critical Rules & What Breaks Query Macros

- **Do NOT chain `->paginate()` after `->atomicCache()`:**
  - `atomicCache()` executes `->get()` immediately and returns an Eloquent/Support `Collection`. Chaining `->paginate()` afterward will throw a fatal error: `Method paginate does not exist on Collection`.
  - *Solution:* For pagination, use the Service Layer's `rememberCollection()` method (see Layer 4).
- **Mass updates will not clear query macro caches:**
  - If you cache a query via `Product::where('active', 1)->atomicCache()`, running `Product::where('active', 1)->update(['price' => 20])` will not clear the cache because no Eloquent event fires.
  - *Solution:* Call `Product::flushCache();` after the update.
- **Raw SQL subqueries are not auto-tagged:**
  - While standard `join()` and `leftJoin()` methods are inspected and auto-tagged, raw SQL joins or subqueries (`whereRaw('exists (select 1 from categories ...)')`) cannot be parsed for table names.
  - *Solution:* Pass additional table tags explicitly: `->atomicCache(3600, tags: ['categories'])`.
- **Parameter order vigilance:**
  - The signature is `atomicCache($keyOrTtl = null, $ttl = null, array $tags = [])`. Passing `$query->atomicCache(3600)` treats `3600` as TTL. If you want a custom key, pass the key first: `$query->atomicCache('my_key', 3600)`.

---

## Layer 4: Service & Repository Layer (`HasAtomicCache`)

Standardize caching in your service or repository classes by adding the `HasAtomicCache` trait.

### 4.1 Automatic Pagination & Filter Isolation (`rememberCollection`)

#### How it works:
When caching paginated or filtered lists, `rememberCollection()` automatically inspects `request()->query()` and hashes the page number and filters into the cache key:

```php
namespace App\Services;

use App\Models\Product;
use KaziOmar\AtomicCache\Traits\HasAtomicCache;

class ProductService
{
    use HasAtomicCache;

    protected string $cacheResource = 'products'; // Resource name and cache tag

    public function getActiveProducts()
    {
        // GET /products?page=1 and GET /products?page=2 are cached separately:
        return $this->rememberCollection('active', fn() => Product::where('status', 'active')->paginate(15));
    }
}
```

---

### 4.2 Single Item Caching (`rememberItem`)

```php
public function findProduct(int $id)
{
    // Caches under key "products:{id}", tagged with "products:{id}" and "products":
    return $this->rememberItem($id, fn() => Product::findOrFail($id));
}
```

---

### 4.3 Query Parameter Sanitization & Whitelisting

To prevent **Cache Fragmentation**, `rememberCollection` automatically strips common marketing and tracking query parameters (`utm_*`, `fbclid`, `gclid`, `ttclid`, `ref`, etc.). When 10,000 visitors arrive from ad campaigns with unique tracking IDs, they all share the exact same cached payload!

To enforce an explicit whitelist of allowed query parameters:
```php
class ProductService
{
    use HasAtomicCache;

    // Only these parameters will be hashed into the cache key:
    protected array $allowedQueryParams = ['page', 'sort', 'category'];
}
```

---

### 4.4 DoS & Cache Poisoning Limits

To protect against memory exhaustion attacks, Atomic Cache enforces strict limits on request queries:
- **Max Parameters (Default: 20):** Queries exceeding 20 parameters cleanly bypass the cache and run against the database.
- **Max Size (Default: 2048 bytes):** Serialized query strings exceeding 2KB cleanly bypass caching.

---

### 4.5 Critical Rules & What Breaks Service Caching

- **Do NOT use `remember()` for paginated data:**
  - If you call `$this->remember('active_products', 3600, fn() => Product::paginate(15))` instead of `rememberCollection()`, it will **not** inspect `request()->query()`. Page 1 results will be cached and returned for Page 2, Page 3, and all other pages! Always use `rememberCollection()`.
- **Resource name guessing mismatches:**
  - If your service class is named `CatalogManager`, the trait guesses the resource tag as `'catalog_managers'`. If your database table is `products`, `Product` model events will clear `'products'`, leaving `'catalog_managers'` stale!
  - *Solution:* Always explicitly declare `protected string $cacheResource = 'products';` when the class name does not match the table name.
- **Un-serializable objects in `$extraArgs`:**
  - Passing closures with un-serializable references into `$extraArgs` will prevent deterministic cache key generation. Pass scalar values (integers, strings, arrays) instead.

---

## Layer 5: Blade Fragment Caching (`@atomicCache`)

### 5.1 Usage & Tagging

Cache expensive HTML blocks, navigation bars, and widgets directly in your Blade views:

```blade
{{-- Caches rendered HTML for 3600 seconds, tagged with 'categories' and 'sub_categories' --}}
@atomicCache('sidebar_categories', 3600, ['categories', 'sub_categories'])
    <div class="sidebar">
        @foreach(\App\Models\Category::with('subcategories')->get() as $category)
            <h3>{{ $category->name }}</h3>
            @foreach($category->subcategories as $sub)
                <p>{{ $sub->title }}</p>
            @endforeach
        @endforeach
    </div>
@endAtomicCache
```

---

### 5.2 Critical Rules & What Breaks Blade Caching

- **Multi-table dependencies:** If your cached Blade fragment renders data from multiple models/tables (such as categories and subcategories), specify all relevant tags: `@atomicCache('sidebar', 3600, ['categories', 'sub_categories'])`. If you only pass `'categories'`, updates to subcategories will not invalidate the cached HTML.
- **Never cache `@csrf` or forms inside a fragment:**
  - CSRF tokens are unique to each user session. If you cache a form containing `@csrf`, the first user's CSRF token is stored. When subsequent users submit the form, they will receive a `419 Page Expired` error!
- **Never cache user-specific data without user scoping:**
  - If you cache a block containing `@atomicCache('user_header')` with the user's name or cart count, User A's personal information will be cached and shown to User B (a major data leak).
  - *Solution:* Append the user ID to the key: `@atomicCache('user_header_' . auth()->id(), 3600)`.
- **Never cache session flash messages or `@error` directives:**
  - `session('status')` or `@error('email')` cached inside a fragment will either never display or remain permanently visible to all subsequent visitors.
- **Unclosed `@atomicCache` blocks:**
  - Omitting `@endAtomicCache` leaves an active PHP output buffer open, which can break page rendering.

---

## Layer 6: CLI Diagnostics & Auditing

### 6.1 Model Architecture Auditor (`atomic-cache:check`)

Scan your entire application's Eloquent models to verify cache invalidation tags and inspect architectural health:

```bash
php artisan atomic-cache:check
php artisan atomic-cache:check --path=app/Domain/Shop/Models
php artisan atomic-cache:check --filter=recommended
php artisan atomic-cache:check --json
```

---

### 6.2 Performance Benchmark CLI (`atomic-cache:bench`)

Benchmark your cache latency, Gzip payload reduction, and chunking integrity in real-time:

```bash
php artisan atomic-cache:bench
php artisan atomic-cache:bench --driver=redis --iterations=10
```

---

### 6.3 Safe Cache Clearing CLI (`atomic-cache:clear`)

```bash
# Clear a specific tag or key:
php artisan atomic-cache:clear products

# Clear multiple tags simultaneously:
php artisan atomic-cache:clear --tags=products,categories

# Clear entire cache store (requires confirmation in production):
php artisan atomic-cache:clear --all

# Clear entire store non-interactively (e.g. CI / deployments):
php artisan atomic-cache:clear --all --force
```

> [!WARNING]
> Running `--all` flushes the **entire** cache store via `flush()`. If your cache store is shared with Redis sessions or queue workers, use `--tags=` or configure a dedicated cache store to avoid purging session data.

---

## Driver Compatibility Matrix

Atomic Cache dynamically evaluates your cache store: taggable stores utilize native tags, while non-taggable stores automatically receive virtual tagging.

| Driver | Tag Support | Stampede Locks | CI Matrix Status | Notes |
| :--- | :--- | :--- | :--- | :--- |
| **`redis`** | **Native** | Yes | Tested in CI | Recommended for high-concurrency production. In production with high writes, schedule Laravel's `php artisan cache:prune-stale-tags`. |
| **`file`** | **Virtual** | Yes | Tested in CI | Default local dev store. Virtual tags provide zero-crash tagging. FileStore supports locks natively. |
| **`database`** | **Virtual** | Yes | Compatible | Works on MySQL, PostgreSQL, SQLite, and SQL Server without extra setup. |
| **`array`** | **Native** | Yes | Tested in CI | In-memory per process (ArrayStore is taggable). Ideal for PHPUnit and automated test suites. |
| **`memcached`**| **Native** | Yes | Compatible | 1 MB item limit: the transparent 800 KB chunk guard and Gzip compression protect against key overflow. |
| **`dynamodb`** | **Virtual** | Yes | Compatible | Items limited to 400 KB; configure `ATOMIC_CACHE_CHUNK_MAX_BYTES` below 400,000. |

---

## Configuration Reference

Publish `config/atomic-cache.php` to customize package behavior:

```php
return [
    'enabled' => env('ATOMIC_CACHE_ENABLED', true),
    'bypass_during_transactions' => env('ATOMIC_CACHE_BYPASS_DURING_TRANSACTIONS', true),
    'strict_mode' => env('ATOMIC_CACHE_STRICT_MODE', null),
    'default_ttl' => env('ATOMIC_CACHE_DEFAULT_TTL', 3600),
    'store' => env('ATOMIC_CACHE_STORE', null),
    'prefix' => env('ATOMIC_CACHE_PREFIX', 'atomic'),

    'stampede' => [
        'enabled' => env('ATOMIC_CACHE_STAMPEDE_ENABLED', true),
        'lock_seconds' => env('ATOMIC_CACHE_LOCK_SECONDS', 10),
        'wait_seconds' => env('ATOMIC_CACHE_WAIT_SECONDS', 5),
    ],

    'compression' => [
        'enabled' => env('ATOMIC_CACHE_COMPRESSION_ENABLED', true),
        'threshold' => env('ATOMIC_CACHE_COMPRESSION_THRESHOLD', 51200), // 50 KB
        'level' => env('ATOMIC_CACHE_COMPRESSION_LEVEL', 6),
    ],

    'chunking' => [
        'enabled' => env('ATOMIC_CACHE_CHUNKING_ENABLED', true),
        'threshold' => env('ATOMIC_CACHE_CHUNKING_THRESHOLD', 1000),
        'chunk_size' => env('ATOMIC_CACHE_CHUNK_SIZE', 1000),
        'max_bytes' => env('ATOMIC_CACHE_CHUNK_MAX_BYTES', 800000), // 800 KB ceiling
        'clean_on_put' => env('ATOMIC_CACHE_CHUNKING_CLEAN_ON_PUT', false),
    ],

    'deduplication' => [
        'enabled' => env('ATOMIC_CACHE_DEDUPLICATION_ENABLED', false),
    ],

    'self_healing' => [
        'enabled' => env('ATOMIC_CACHE_SELF_HEALING', true),
        'log_corrupted' => env('ATOMIC_CACHE_LOG_CORRUPTED', true),
    ],

    'jitter' => [
        'enabled' => env('ATOMIC_CACHE_JITTER_ENABLED', false),
        'percentage' => env('ATOMIC_CACHE_JITTER_PERCENTAGE', 5), // ±5%
    ],

    'ignored_query_parameters' => [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'dclid', 'wbraid', 'gbraid', 'ttclid', 'msclkid',
        'mc_cid', 'mc_eid', '_ga', '_gl', '_gid', 'ref',
    ],

    'query_limits' => [
        'max_params' => env('ATOMIC_CACHE_QUERY_MAX_PARAMS', 20),
        'max_bytes' => env('ATOMIC_CACHE_QUERY_MAX_BYTES', 2048),
    ],
];
```

### Environment Variables (.env) Reference

| Variable | Default | Description |
| :--- | :--- | :--- |
| `ATOMIC_CACHE_ENABLED` | `true` | Master switch to enable or disable atomic caching globally |
| `ATOMIC_CACHE_DEFAULT_TTL` | `3600` | Default cache lifetime in seconds when none is specified |
| `ATOMIC_CACHE_STORE` | `null` | Dedicated cache store (e.g. `'redis'`); `null` uses `config('cache.default')` |
| `ATOMIC_CACHE_PREFIX` | `atomic` | Key namespace prefix (in multi-tenant apps, set uniquely per tenant) |
| `ATOMIC_CACHE_STAMPEDE_ENABLED` | `true` | Enable atomic distributed locking by default |
| `ATOMIC_CACHE_LOCK_SECONDS` | `10` | Maximum lock hold duration during closure calculation |
| `ATOMIC_CACHE_WAIT_SECONDS` | `5` | Maximum seconds concurrent requests will wait for computation |
| `ATOMIC_CACHE_COMPRESSION_ENABLED`| `true` | Auto-compress payloads exceeding compression threshold |
| `ATOMIC_CACHE_COMPRESSION_THRESHOLD`| `51200` | Minimum serialized byte size to trigger Gzip compression (50 KB) |
| `ATOMIC_CACHE_CHUNKING_ENABLED` | `true` | Auto-partition large datasets into driver-safe chunk keys |
| `ATOMIC_CACHE_CHUNKING_THRESHOLD` | `1000` | Item count threshold required to trigger transparent chunking |
| `ATOMIC_CACHE_CHUNK_SIZE` | `1000` | Item count per partitioned chunk |
| `ATOMIC_CACHE_CHUNK_MAX_BYTES` | `800000` | Maximum byte ceiling per chunk payload (800 KB for Memcached 1MB limit) |
| `ATOMIC_CACHE_CHUNKING_CLEAN_ON_PUT`| `false` | When true, non-chunked put purges prior chunk keys eagerly |
| `ATOMIC_CACHE_DEDUPLICATION_ENABLED`| `false` | Opt-in DNA hashing: skips compression and chunk re-splitting if identical content is cached |
| `ATOMIC_CACHE_JITTER_ENABLED` | `false` | Enable random TTL variance globally |
| `ATOMIC_CACHE_JITTER_PERCENTAGE` | `5` | Jitter variance percentage (±5%) |
| `ATOMIC_CACHE_QUERY_MAX_PARAMS` | `20` | Max allowed sanitized HTTP query parameters before bypassing cache |
| `ATOMIC_CACHE_QUERY_MAX_BYTES` | `2048` | Max allowed serialized query string size before bypassing cache |

---

## Troubleshooting & FAQ

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

*Note on Native Redis Tags:* When using Redis native tags (`TaggedCache`), Laravel's underlying tag set key (`tag:orders:entries`) is not prefixed by custom runtime prefixes unless the Redis cache store connection itself has a dedicated prefix. In single-Redis shared multi-tenant databases, invalidating `AtomicCache::clearTags(['orders'])` in Tenant A will also flush the native `orders` tag set in Tenant B (causing over-invalidation, though never data leakage). To achieve complete tag isolation across tenants in Redis, assign each tenant a dedicated Redis database or connection prefix.

---

## Upgrade Guide

Atomic Cache adheres to Semantic Versioning:

| Release | Change | Impacted Workloads | Recommended Action |
| :--- | :--- | :--- | :--- |
| **`v1.4.4` (Unreleased)** | **Transaction Guard, Scoped Query Macro, $cacheIgnoreColumns, Strict Mode, and Universal OCC** | All workloads. | OCC now operates universally via atomic UUID version tokens across all drivers. Added Transaction Guard (`bypass_during_transactions`), `$cacheIgnoreColumns` for counter updates, Strict Mode validation, `scope:` query macro parameter, and `AtomicCache::invalidating()` wrapper. |
| **`v1.4.3`** | **BackedEnum Foreign Keys & Magic `__isset` Bypass** | Models using PHP 8.1+ Enums as foreign keys. | Foreign keys backed by enums are cleanly unwrapped before tag generation. Fixed reflection lookups to avoid triggering Eloquent magic `__isset`. |
| **`v1.4.2`** | **Transaction-Safe Deferred Parent Invalidation** | Models using `$cacheParents` updated inside `DB::transaction(...)`. | Tags are resolved at event dispatch time before `syncOriginal()`, capturing old & new parent keys accurately after commit. |
| **`v1.4.0`** | **Smart Parent Invalidation & Architecture Linter** | Applications declaring parent relationships (`$cacheParents`). | Run `php artisan atomic-cache:check` to audit Eloquent relationship caching health. |
| **`v1.3.8`** | **Optimistic Concurrency Control (OCC) & Query DoS Limits** | Write-heavy models and long filter query strings. | Cache computation aborted if tags changed mid-flight. Requests exceeding 20 query parameters bypass cache to prevent DoS. |
| **`v1.3.7`** | **Chunk Generation IDs & Tag-Scoped Flush** | Transparent chunking users. | Chunk keys utilize `{key}:__chunk__:{generation}:{i}` to eliminate torn reads. Old chunk formats remain backwards-compatible. |
| **`v1.3.4`** | **Opt-in Deduplication & Event Hooks** | `AutoClearsCache` now listens to `created`, `updated`, `deleted`, `restored`. | `increment()` and `decrement()` trigger invalidation. Deduplication is opt-in via `ATOMIC_CACHE_DEDUPLICATION_ENABLED=true`. |

---

## Testing

Run the test suite using PHPUnit:

```bash
composer test
# or
vendor/bin/phpunit
```

---

## Credits & License

- **Kazi Omar Faruk** ([@kaziomarsh24](https://github.com/kaziomarsh24))
- Built with high-performance distributed architecture for modern Laravel applications.
- Licensed under the **MIT License**. Please see [License File](LICENSE) for more information.
