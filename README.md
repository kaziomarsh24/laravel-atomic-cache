# Laravel Atomic Cache

[![Run Tests](https://github.com/kaziomarsh24/laravel-atomic-cache/actions/workflows/run-tests.yml/badge.svg)](https://github.com/kaziomarsh24/laravel-atomic-cache/actions)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20to%208.5-777BB4.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)

> ### High-Performance, Stampede-Proof Caching Engine for Laravel
> Eliminates cache stampedes using atomic distributed locks, auto-compresses large payloads (>50KB) by 70–80%, auto-partitions mega datasets across driver-safe chunks, brings zero-crash virtual tagging to File and Database drivers, and guarantees transaction-safe model invalidation (`DB::afterCommit`).

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
  - [2.3 Model-Level Manual Cache Flushing](#23-model-level-manual-cache-flushing)
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
| **Large Payload Size** | Raw uncompressed serialization | **Auto Gzip** (>50KB cuts Redis RAM by 70–80%) |
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

Follow these **3 simple steps** to get production-grade caching immediately:

### Step 1: Add the Trait to your Model
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class Product extends Model
{
    use AutoClearsCache; // Clears related caches on commit when saved or deleted
}
```

### Step 2: Query directly with `->atomicCache()`
```php
// In any Controller, Action, or Livewire component:
$products = Product::where('status', 'active')->latest()->atomicCache(3600);
$product = Product::where('slug', $slug)->atomicCacheFirst(3600);
```

### Step 3: Run the Architecture Audit
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

The **Core Engine (`AtomicCache::`)** is completely self-contained, fully explicit, and rock-solid. While query macros and traits provide convenient shortcuts, you can build your entire application directly with the Core Engine for 100% predictable control.

---

## Layer 1: The Core Caching Engine (`AtomicCache::`)

The `AtomicCache` facade provides manual, high-performance caching with enterprise concurrency controls.

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

// Standard CRUD operations:
AtomicCache::put('site_settings', $settings, 86400); // Store directly
$value = AtomicCache::get('site_settings');          // Retrieve
$exists = AtomicCache::has('site_settings');         // Check existence
$val = AtomicCache::pull('site_settings');           // Retrieve and delete
AtomicCache::forget('site_settings');                // Delete single key
```

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
- **Using a cache store without lock support:** Drivers that do not implement `Illuminate\Contracts\Cache\LockProvider` (such as default local `file` cache without a lock provider) cannot create atomic locks. The engine safely detects this and bypasses locking, executing the callback directly.
- **Calculation exceeding `lockSeconds`:** If your computation takes 20 seconds but `lockSeconds` is set to 10 seconds, the lock will expire prematurely while the first worker is still running, allowing waiting workers to enter and recompute. Always set `lockSeconds` higher than your maximum expected computation time.
- **`waitSeconds` too short:** If computation takes 4 seconds but `waitSeconds` is set to 1 second, waiting requests will time out waiting for the lock and fall back to executing the query themselves.

---

### 1.3 Virtual Tagging Engine (Native & Non-Taggable Stores)

#### How it works:
In standard Laravel, calling `Cache::tags(['products'])` on `file` or `database` cache drivers crashes with a fatal exception: `This cache store does not support tagging.`

Atomic Cache introduces a **Virtual Tag Versioning Engine**:
- On **Redis / Memcached**: Uses native driver tags for maximum raw performance.
- On **File / Database / SQLite / Array**: Embeds atomic UUID version tokens into cache keys (`{prefix}:{key}:vt:{hash}`).
- When `AtomicCache::clearTags(['products'])` is called, it increments/replaces the virtual tag UUID in 0ms. All cached entries under that tag instantly experience a cache miss without requiring slow file directory traversals or heavy SQL deletes.

```php
// Tagging reads and writes (works identically on Redis, File, or Database):
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
When you perform operations that do not trigger automated model events (such as bulk updates or direct SQL queries), use these manual invalidation methods:

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
- Reduces Redis/Memcached memory footprint by **70% to 80%**.
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
When `ATOMIC_CACHE_DEDUPLICATION_ENABLED=true` is enabled in `.env`, Atomic Cache hashes the payload (`Cache DNA`) before writing. If identical content is already cached with a valid remaining TTL, **it skips CPU-intensive serialization, Gzip re-compression, and chunk re-splitting**, merely refreshing the TTL in storage.

#### What will break it / What causes it not to work properly:
- **Non-deterministic payloads:** If your cached array includes dynamic timestamps like `now()` or random tokens, every payload hash will differ, bypassing deduplication benefits.

---

### 1.10 Optimistic Concurrency Control (OCC)

#### How it works:
If a heavy calculation takes 4 seconds, and during second 2 an admin updates the database (flushing the tag), writing the 4-second-old result to the cache at second 4 would overwrite the update with stale data.

Atomic Cache verifies tag version identifiers **before** and **after** running the closure. If tags were modified while the closure ran, the stale write is safely **discarded**.

#### What will break it / What causes it not to work properly:
- **Write-heavy tables with broad tags:** On tables with continuous concurrent updates (e.g., streaming telemetry), OCC will repeatedly discard stale writes. For high-frequency write tables, cache targeted single-item keys instead of broad table collections.

---

### 1.11 Self-Healing Engine

#### How it works:
If a cached payload is corrupted, truncated by memory limits, or missing a chunk, Atomic Cache catches the deserialization exception, logs the event, purges the broken key, and silently recomputes fresh data from the callback without crashing the user's HTTP request.

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
Declare parent relationships to automatically invalidate parent-scoped cache tags whenever a child record changes:

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

---

### 2.3 Model-Level Manual Cache Flushing

`AutoClearsCache` provides two intuitive helper methods directly on the model:

```php
// 1. Instance helper: manually clear this record and its parent tags:
$product = Product::find(42);
$product->clearCache(); // Expressive alias for clearModelCacheOnCommit()

// 2. Static helper: flush all collection-level caches for this model's table:
// Ideal after mass updates or direct DB queries:
Product::where('category_id', 5)->update(['discount' => 10]);
Product::flushCache(); // Wipes all "products" collection caches in one line!
```

---

### 2.4 Critical Rules & What Breaks Model Auto-Invalidation

- **Mass queries bypass model events:** Direct query builder mutations (`Product::where(...)->update(...)`, `delete()`, or `DB::table('products')->update(...)`) **do not fire Eloquent events**. AutoClearsCache cannot detect them. 
  - *Solution:* Run `Product::flushCache();` right after your bulk query.
- **Quiet operations bypass events:** Calling `$model->saveQuietly()`, `updateQuietly()`, `deleteQuietly()`, or wrapping code in `Model::withoutEvents()` intentionally suppresses model events.
  - *Solution:* Manually call `$model->clearCache()` if you need invalidation after quiet saves.
- **`$cacheParents` only supports `BelongsTo`:** Declaring `hasMany`, `hasOne`, `belongsToMany`, or `morphTo` in `$cacheParents` is not supported and will be silently ignored.
- **Custom owner keys are skipped:** If a relationship uses a custom non-primary owner key (e.g. `belongsTo(Store::class, 'store_slug', 'slug')`), it is skipped because the child model holds a string slug rather than the parent's primary ID, which would create mismatched umbrella tags (`stores:nike` vs `stores:5`).
- **Grandparent relations do not auto-cascade:** Multi-level hierarchies (e.g. `OrderItem -> Order -> Store`) do not automatically cascade from item to store. Updating an `OrderItem` invalidates `Order`, but will not clear `Store` unless `Order` is touched.
- **Runtime property mutation is ignored:** Because configured parent relations are cached in-memory per class for performance, dynamically modifying `$model->cacheParents = [...]` on an active model instance at runtime will have no effect.
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

// 4. Works on DB facade and relationships:
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
{{-- Caches rendered HTML for 3600 seconds, tagged with 'categories' --}}
@atomicCache('sidebar_categories', 3600, ['categories'])
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
# Clear a specific tag:
php artisan atomic-cache:clear products

# Clear multiple tags:
php artisan atomic-cache:clear --tags=products,categories

# Clear entire store (requires confirmation in production):
php artisan atomic-cache:clear --all
```

> [!WARNING]
> Running `--all` flushes the **entire** cache store. If your cache store is shared with Redis sessions or queue workers, use `--tags=` to avoid purging session data.

---

## Driver Compatibility Matrix

Atomic Cache dynamically evaluates your cache store: taggable stores utilize native tags, while non-taggable stores automatically receive virtual tagging.

| Driver | Tag Support | Stampede Locks | CI Matrix Status | Notes |
| :--- | :--- | :--- | :--- | :--- |
| **`redis`** | **Native** | Yes | Tested in CI | Recommended for high-concurrency production. Includes direct Redis tag purging on Laravel 10 + phpredis 6.1+. |
| **`file`** | **Virtual** | Yes | Tested in CI | Default local dev store. Virtual tags provide zero-crash tagging. Orphan files expire naturally by TTL. |
| **`database`** | **Virtual** | Yes | Compatible | Works on MySQL, PostgreSQL, SQLite, and SQL Server without extra setup. |
| **`array`** | **Native** | Yes | Tested in CI | In-memory per process. Ideal for PHPUnit and automated test suites. |
| **`memcached`**| **Native** | Yes | Compatible | 1 MB item limit: the transparent 800 KB chunk guard and Gzip compression protect against key overflow. |
| **`dynamodb`** | **Virtual** | Yes | Compatible | Items limited to 400 KB; configure `ATOMIC_CACHE_CHUNK_MAX_BYTES` below 400,000. |

---

## Configuration Reference

Publish `config/atomic-cache.php` to customize package behavior:

```php
return [
    'enabled' => env('ATOMIC_CACHE_ENABLED', true),
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

---

## Troubleshooting & FAQ

### 1. The cache is not cleared after updating data
| Possible Cause | Recommended Fix |
| :--- | :--- |
| **Mass Eloquent Mutations:** `Model::where()->update()`, `delete()`, `insert()`, or `DB::table()`. These bypass Eloquent model events. | Call `Product::flushCache();` or `AtomicCache::clearCollection('products')` right after the bulk query. |
| **Missing Trait:** Model does not use `AutoClearsCache`. | Add `use AutoClearsCache;` to the model. Run `php artisan atomic-cache:check` to audit your models. |
| **Quiet Operations:** Using `saveQuietly()`, `updateQuietly()`, or `withoutEvents()`. | These suppress model events by design. Call `$product->clearCache()` manually. |
| **Rolled-back Transactions:** The write ran inside a `DB::transaction(...)` that rolled back. | `AutoClearsCache` uses `DB::afterCommit`. If the transaction rolls back, no database changes occurred, so cache remains intact. |
| **Custom Owner Keys:** A child model uses a custom owner key (e.g. `belongsTo(Store::class, 'slug', 'slug')`). | `$cacheParents` automatically skips custom owner keys to prevent mismatched umbrella tags. Attach parent tags manually if needed. |

---

### 2. The cache never hits (always a miss)
| Possible Cause | Recommended Fix |
| :--- | :--- |
| **Caching Disabled:** `ATOMIC_CACHE_ENABLED=false` in `.env`. | Enable it in your environment: `ATOMIC_CACHE_ENABLED=true`. |
| **Query Parameter Limits Exceeded:** The request has more than 20 query parameters or query string exceeds 2048 bytes. | AtomicCache bypasses caching on abnormal queries to protect against Cache Poisoning DoS attacks. Adjust `ATOMIC_CACHE_QUERY_MAX_PARAMS` or `ATOMIC_CACHE_QUERY_MAX_BYTES` if legitimate requests are large. |
| **OCC Discard on High Writes:** Tags were invalidated while the `remember()` closure was computing. | Expected behavior under Optimistic Concurrency Control. On write-heavy tables, use targeted single-record keys instead of broad table tags. |
| **Computed TTL <= 0:** TTL is 0, negative, or in the past. | AtomicCache forgets/clears keys when given a non-positive TTL or past `DateTimeInterface`. Verify your TTL logic. |

---

## Upgrade Guide

Atomic Cache adheres to Semantic Versioning:

| Release | Change | Impacted Workloads | Recommended Action |
| :--- | :--- | :--- | :--- |
| **`v1.4.4`** | **Model Manual Flush Helpers & Hardened Reflection** | All models using `AutoClearsCache`. | Added `$model->clearCache()` instance helper and `Model::flushCache()` static helper for convenient invalidation after bulk updates. |
| **`v1.4.2`** | **Transaction-Safe Deferred Parent Invalidation** | Models using `$cacheParents` updated inside `DB::transaction(...)`. | Tags are resolved at event dispatch time before `syncOriginal()`, capturing old & new parent keys accurately after commit. |
| **`v1.4.0`** | **Smart Parent Invalidation & Architecture Linter** | Applications declaring parent relationships (`$cacheParents`). | Run `php artisan atomic-cache:check` to audit Eloquent relationship caching health. |
| **`v1.3.8`** | **Optimistic Concurrency Control (OCC) & Query DoS Limits** | Write-heavy models and long filter query strings. | Cache computation aborted if tags changed mid-flight. Requests exceeding 20 query parameters bypass cache to prevent DoS. |
| **`v1.3.7`** | **Chunk Generation IDs & Tag-Scoped Flush** | Transparent chunking users. | Chunk keys utilize `{key}:__chunk__:{generation}:{i}` to eliminate torn reads. Old chunk formats remain backwards-compatible. |

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
