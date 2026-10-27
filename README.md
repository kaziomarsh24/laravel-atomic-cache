# Laravel Atomic Cache ⚡

[![Run Tests](https://github.com/kaziomarsh24/laravel-atomic-cache/actions/workflows/run-tests.yml/badge.svg)](https://github.com/kaziomarsh24/laravel-atomic-cache/actions)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20to%208.5-777BB4.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)

> ### 💡 Hate cache invalidation bugs? Tired of managing cache keys?
> **What if caching in Laravel was just 2 lines of code with ZERO configuration?**
>
> 1. In your Model: `use AutoClearsCache;`
> 2. In your Query: `Product::where('status', 'active')->atomicCache();`
>
> *Done! No cache keys to invent. No Observers to register. Automatically cleared on DB commit. Zero crashes on local dev without Redis.*

**Laravel Atomic Cache** is an enterprise-grade caching engine for Laravel. It eliminates cache stampedes (dogpiling) using atomic distributed locks, auto-compresses large payloads by up to 70–80% (depending on payload entropy), auto-partitions mega datasets across driver-safe chunks, brings zero-crash virtual tagging to File and Database drivers, and guarantees transaction-safe model invalidation (`DB::afterCommit`).

```php
use App\Models\Product;
use KaziOmar\AtomicCache\Facades\AtomicCache;

// 1. Zero-Key Query Caching: auto-generates key, auto-tags table, auto-clears on DB commit!
$featured = Product::where('status', 'active')->latest()->atomicCache(3600);

// 2. Cache Stampede Protection: 1,000 concurrent requests only run 1 query; others wait & read
$report = AtomicCache::rememberWithLock('monthly_report', fn() => Report::generate());
```

---

## 📑 Table of Contents

- [Why Laravel Atomic Cache?](#why-laravel-atomic-cache)
- [When NOT to Cache](#-when-not-to-cache)
- [Requirements](#requirements)
- [Installation](#installation)
- [⚡ Quickstart in 10 Seconds (Zero-Config Guide)](#-quickstart-in-10-seconds-zero-config-guide)
- [Core Features & Usage](#core-features--usage)
  - [1. Basic Caching](#1-basic-caching)
  - [2. Stampede Protection (rememberWithLock)](#2-stampede-protection-rememberwithlock)
  - [3. Auto Payload Compression (Gzip)](#3-auto-payload-compression-gzip)
  - [4. Transparent Chunking (Large Datasets & Collections)](#4-transparent-chunking-large-datasets--collections)
  - [5. Write Deduplication (Cache DNA)](#5-write-deduplication-cache-dna)
  - [6. Virtual Tagging Engine (File & Database Fallback)](#6-virtual-tagging-engine-file--database-fallback)
  - [7. Cache TTL Jitter (Thundering Herd Prevention)](#7-cache-ttl-jitter-thundering-herd-prevention)
  - [8. User-Scoped Caching (rememberForUser)](#8-user-scoped-caching-rememberforuser)
  - [9. Fluent Chainable Builder](#9-fluent-chainable-builder)
  - [10. Blade Fragment Caching](#10-blade-fragment-caching)
  - [11. Artisan CLI Diagnostics & Benchmark](#11-artisan-cli-diagnostics--benchmark)
  - [12. Cache Clearing Command](#12-cache-clearing-command)
  - [13. Eloquent & DB Query Macros (Zero-Key Caching)](#13-eloquent--db-query-macros-zero-key-caching)
  - [14. Type-Safe Model Tags](#14-type-safe-model-tags)
  - [15. Model Audit & Invalidation Inspector](#15-model-audit--invalidation-inspector)
- [Eloquent Model Lifecycle (AutoClearsCache)](#eloquent-model-lifecycle-autoclearscache)
- [Service & Repository Layer (HasAtomicCache)](#service--repository-layer-hasatomiccache)
  - [Safe Argument Normalization](#safe-argument-normalization)
  - [Automatic Pagination & Filter Isolation](#automatic-pagination--filter-isolation-with-smart-tracking-sanitization)
- [🏪 Real-World Example: E-Commerce Store](#-real-world-example-e-commerce-store)
- [Configuration Reference](#configuration-reference)
- [Testing](#testing)
- [Credits & License](#credits--license)

---

## 💡 Why Laravel Atomic Cache?

| Feature | Standard Laravel Cache | Laravel Atomic Cache |
| :--- | :--- | :--- |
| **Stampede Protection** | ❌ All concurrent requests hit DB | ✅ **Atomic Lock** (1 worker computes, others wait & read) |
| **Large Payload Size** | ❌ Raw uncompressed serialization | ✅ **Auto Gzip** (>50KB cuts Redis RAM by 70–80%) |
| **Mega Datasets** | ❌ Fails on 1MB Memcached / Redis freeze | ✅ **Transparent Chunking** (splits safely, preserves keys) |
| **Redundant Writes** | ❌ Every put hits storage/network | ✅ **Write Deduplication** (opt-in DNA hashing; skips re-compression and chunk re-splitting) |
| **Driver Tag Support** | ❌ Crashes on File/Database stores | ✅ **Virtual Tagging** (works across all stores without Redis) |
| **Corrupted Data** | ❌ Throws crash exception to user | ✅ **Self-Healing** (cleans key, regenerates silently) |
| **Simultaneous Expiry** | ❌ All keys expire in same second | ✅ **TTL Jitter** (random variance prevents herd spikes) |
| **Model Invalidation** | ⚠️ Fragile observers before commit | ✅ **DB::afterCommit** (prevents transaction race conditions) |
| **Query & Filter Caching** | ⚠️ Manual key hashing required | ✅ **Zero-Key Macros & Service Isolation** (automatic hashing & tagging) |
| **Diagnostics & Audit** | ❌ DIY logging | ✅ **Artisan Benchmark CLI** (`atomic-cache:bench`) |

---

## 🛑 When NOT to Cache

Caching is not suitable for every workload. To maintain system reliability, avoid caching in these scenarios:

- **Real-Time WebSockets & Chat Messages:** Chat messages (`ChatMessage`) should be pushed directly via Laravel Reverb or Pusher. Caching them creates unnecessary memory churn without read benefits.
- **High-Frequency GPS & Telemetry:** Live driver/rider coordinates streaming every few seconds belong in Redis Pub/Sub, Kafka, or ClickHouse, not application-level cache.
- **Financial Transactions & Stock Deductions:** Real-time wallet balances, inventory decrements, and audit logs require pessimistic database row locks (`lockForUpdate()`) to guarantee absolute transactional consistency.

---

## ⚙️ Requirements

- **PHP:** `8.2`, `8.3`, `8.4`, or `8.5`
- **Laravel Framework:** `10.x`, `11.x`, `12.x`, or `13.x`
- **PHP Extensions:** `ext-zlib` (optional for compression, built-in on 99% of PHP installs)

---

## 📦 Installation

Install via Composer:

```bash
composer require thekaziomar/laravel-atomic-cache
```

Publish the optional configuration file:

```bash
php artisan vendor:publish --tag="atomic-cache-config"
```

---

## ⚡ Quickstart in 10 Seconds (Zero-Config Guide)

Don't want to think about cache keys, tag names, or redis commands? Just follow these **3 simple steps**:

### Step 1: Add the Trait to your Model
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class Product extends Model
{
    use AutoClearsCache; // 👈 Auto-invalidates queries when saved/deleted on commit
}
```

### Step 2: Query directly with `->atomicCache()`
```php
// In any Controller, Action, or Livewire component:
$products = Product::where('status', 'active')->latest()->atomicCache(3600);
$singleProduct = Product::where('slug', $slug)->atomicCacheFirst(3600);
```
*No cache keys to remember! Automatically named, automatically tagged with table, and automatically cleared on database commit.*

### Step 3: Run the Model Auditor
```bash
php artisan atomic-cache:check
```
*Inspect your application to verify which models have automatic caching enabled.*

---

## 🚀 Core Features & Usage

### 1. Basic Caching

Works seamlessly like native Laravel cache, supporting flexible parameter ordering:

```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

// Standard signature: remember($key, $ttl, $callback)
$posts = AtomicCache::remember('posts.all', 3600, function () {
    return Post::where('published', true)->get();
});

// Alternative signature: remember($key, $callback, $ttl)
$posts = AtomicCache::remember('posts.all', fn() => Post::all(), 3600);

// Global helper shortcut
$posts = atomic_cache('posts.all', fn() => Post::all(), 3600);
```

#### Understanding the Parameters:

| Parameter | Type | Description |
| :--- | :--- | :--- |
| **`$key`** | `string` | **Unique Cache Identifier** in your cache store (e.g. `'posts.all'`, `'home.banners'`). Using `{resource}.{action}` or `{module}:{query}` is the industry standard convention to prevent key collisions and enable easy searching in Redis (`redis-cli KEYS "atomic:posts.*"`). |
| **`$ttl`** | `int` | **Time-To-Live in seconds** (e.g. `3600` = 1 hour, `86400` = 1 day). Specifies how long the value remains cached. If omitted, uses `config('atomic-cache.default_ttl', 3600)`. |
| **`$callback`**| `Closure` | **Data Producer Callback**. Executes only when the cache is empty or expired. The returned value is automatically compressed (if >50KB), stored in cache, and returned. |

```php
// Standard CRUD methods:
AtomicCache::put('key', $value, 3600);       // Store an item directly
$val = AtomicCache::get('key', default: null); // Retrieve an item
$exists = AtomicCache::has('key');             // Check existence
$val = AtomicCache::pull('key');              // Retrieve and immediately delete
AtomicCache::clear('key');                    // Delete item (alias of forget)
```

---

### 2. Stampede Protection (`rememberWithLock`)

During sudden traffic surges (e.g. after a cache flush or key expiry), hundreds of requests hit your database simultaneously trying to recalculate the same data.

`rememberWithLock` acquires an atomic distributed lock. **Only one process executes the query**, while concurrent requests wait up to 5 seconds and read the freshly cached result:

```php
$analytics = AtomicCache::rememberWithLock('dashboard.stats', 3600, function () {
    return HeavyAnalyticsService::calculate();
}, lockSeconds: 10, waitSeconds: 5);
```

---

### 3. Auto Payload Compression (Gzip)

Large Eloquent collections or API responses can consume gigabytes of Redis/Memcached RAM. 

Whenever a cached payload exceeds **50 KB** (configurable), Atomic Cache automatically compresses it with `gzcompress`:
- Reduces Redis/Memcached memory usage by up to **70% to 80%** (actual savings depend on payload structure and entropy).
- Decompression is handled transparently on retrieval.
- If payload is smaller than 50 KB, it stores without compression overhead.

---

### 4. Transparent Chunking (Large Datasets & Collections)

Storing large collections (e.g. 10,000+ items or multi-megabyte reports) in a single cache entry can freeze single-threaded Redis processes and exceed Memcached's strict 1MB limit.

Atomic Cache includes **Transparent Chunking**:
- Arrays and Collections exceeding the threshold (1,000+ items or count > chunk_size) are automatically partitioned into safe chunk keys (`{key}:__chunk__:{generation}:{i}`).
- **Generation UUIDs (Zero Torn Reads):** Writes are published under a unique generation UUID before updating the atomic manifest pointer, completely eliminating torn-read race conditions for concurrent readers.
- **Dynamic Byte-Size Guard (`max_bytes`):** Samples row payloads and dynamically adjusts chunk size so each chunk payload stays under 800 KB (driver-safe for Memcached's 1MB limit).
- **Orphan Chunk Lifecycle (`clean_on_put`):** Previous generation chunks are cleaned automatically when a new chunked generation is published. On non-chunked overwrites, prior chunks expire naturally via store TTL, or can be eagerly purged by opting into `ATOMIC_CACHE_CHUNKING_CLEAN_ON_PUT=true`.
- **Single-Roundtrip Batch Reassembly:** Chunks are retrieved simultaneously using Laravel's `many()` (Redis MGET or multi-key read on file/database drivers), avoiding sequential network round-trips.
- **Key preservation:** Associative and sparse numeric keys remain intact upon reassembly.
- **Auto Collection restoration:** Collections are seamlessly reconstituted as `Illuminate\Support\Collection`.
- **Self-Healing recovery:** If an individual chunk is evicted or lost, Atomic Cache evicts the broken metadata and transparently regenerates a fresh payload without crashing your application.

```php
// Storing 25,000 records transparently splits into 25 safe chunks behind the scenes
$dataset = AtomicCache::remember('large_report', 3600, fn() => ExportReport::all());
```

---

### 5. Write Deduplication (Cache DNA)

Repeated writes of identical data waste CPU cycles on serialization and compression.

Atomic Cache includes an optional deduplication engine (`ATOMIC_CACHE_DEDUPLICATION_ENABLED=false` by default). When enabled, it hashes content before writing (`Cache DNA`). When the identical payload is already cached with valid remaining TTL, **it skips CPU-intensive gzip compression and chunk re-splitting, refreshing the existing TTL in the store**:

```php
// With ATOMIC_CACHE_DEDUPLICATION_ENABLED=true:
AtomicCache::put('app_config', $config, 3600); // Compressed & written to store
AtomicCache::put('app_config', $config, 7200); // Same data -> Compression & chunk-splitting skipped, TTL extended to 7200s!
```

---

### 6. Virtual Tagging Engine (File & Database Fallback)

In Laravel, calling `Cache::tags(['users'])` on `file` or `database` cache drivers crashes with `BadMethodCallException: This cache store does not support tagging.`

Atomic Cache introduces a **Virtual Tag Versioning Engine**:
- On Redis / Memcached: Uses native high-performance driver tags.
- On File / Database / SQLite / Array: Tracks atomic UUID version tokens per tag in the cache store.
- When `clearTags(['users'])` is called, it generates a fresh UUID token in the store atomically.
- Subsequent reads experience an instant cache miss in 0ms without running slow directory scans or SQL deletes. *(Note: On Redis/Memcached, orphan entries expire via driver TTL. On local file driver, orphan cache files remain on disk until their TTL expires or until `artisan cache:clear` / prune is run).*

```php
// Fluent tag flush (standard Laravel syntax)
AtomicCache::tags(['orders'])->flush();

// Flush specific tags directly
AtomicCache::flushTags(['dashboard', 'reports']);

// Invalidate a single model item: flushes tag "users:42"
AtomicCache::forgetItem('users', 42); // alias: clearItem()

// Invalidate an entire collection: flushes tag "users"
AtomicCache::flushCollection('users'); // alias: clearCollection()
```

---

### 7. Cache TTL Jitter (Thundering Herd Prevention)

If you cache 1,000 keys for 3,600 seconds, they will all expire at the exact same microsecond, causing a sudden CPU and database spike.

`withJitter` adds a small random variance (e.g. ±5%) to the TTL. The method accepts a decimal fraction (e.g. `0.05` for ±5%, or `0.10` for ±10%), whereas the `ATOMIC_CACHE_JITTER_PERCENTAGE=5` .env variable takes an integer percentage:

```php
// TTL of 3600s will randomly vary between 3420s and 3780s
AtomicCache::withJitter()->remember('feed.popular', fn() => getFeed(), 3600);

// Or pass an explicit variance (e.g. 10% fraction: 0.10)
AtomicCache::withJitter(0.10)->remember('feed.popular', fn() => getFeed(), 3600);
```

---

### 8. User-Scoped Caching (`rememberForUser`)

Avoid manually concatenating `Auth::id()` into your cache keys:

```php
// Automatically scopes key to "atomic:user:{auth_id}:dashboard_nav"
$menu = AtomicCache::rememberForUser('dashboard_nav', function () {
    return Auth::user()->navigationMenu();
});

// Or scope to a specific user ID
$profile = AtomicCache::rememberForUser('profile', fn() => User::find(99), userId: 99);
```

---

### 9. Fluent Chainable Builder

You can fluently combine any optimizations in a clean builder chain:

```php
$data = AtomicCache::store('redis')
    ->tags(['finance', 'reports'])
    ->ttl(7200)
    ->withLock(lockSeconds: 15, waitSeconds: 5)
    ->withJitter(0.05)
    ->remember('annual_revenue', function () {
        return RevenueCalculator::compute();
    });
```

---

### 10. Blade Fragment Caching

Cache expensive HTML blocks, navigation bars, or sidebar widgets directly in Blade templates:

```blade
{{-- Caches rendered HTML for 3600s, tagged with 'categories' --}}
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

> ⚠️ **Important Security & Scope Note:** Blade fragment caching is global across all visitors by default. Never cache personalized or user-specific content (such as logged-in user names, personal carts, or `@csrf` tokens) in a generic fragment block. If user-scoping is needed, append the user ID to the cache key (e.g. `@atomicCache('user_nav_' . auth()->id(), 3600)`).

---

### 11. Artisan CLI Diagnostics & Benchmark

Atomic Cache ships with an integrated performance benchmarking suite to inspect your real-world speedup and memory efficiency:

```bash
# Run benchmark on current default cache store
php artisan atomic-cache:bench

# Benchmark specific driver (e.g. redis, memcached, file, database)
php artisan atomic-cache:bench --driver=redis --iterations=10

# Export benchmark metrics as JSON
php artisan atomic-cache:bench --format=json
```

**Example Benchmark Output:**
```text
⚡ Running AtomicCache Benchmark against driver [redis] (5 iterations)...

+--------------------+------------------------------+-----------------------------------+----------------------+-------------------------+
| Metric Category    | Scenario                     | Raw / Simulated Baseline          | AtomicCache Result   | Improvement / Status    |
+--------------------+------------------------------+-----------------------------------+----------------------+-------------------------+
| ⚡ Latency / Speed | Database Query vs Cache Hit  | 45.00 ms                          | 0.04 ms              | +99.9% Faster           |
| 📦 Payload Size    | Large Array Gzip Compression | 95.48 KB                          | 4.13 KB              | 95.7% Memory Saved      |
| 🧩 Safe Chunking   | Large Dataset (2,500 items)  | Single raw item (Risk: 1MB limit) | 3 safe chunks        | Passed (Keys Preserved) |
| 🏷️ Tag Resilience  | Non-Redis Virtual Tagging    | Native Tag Support                | Zero-Crash Emulation | Resilient (Zero Crash)  |
+--------------------+------------------------------+-----------------------------------+----------------------+-------------------------+

✨ AtomicCache is operating optimally on [redis] driver with full stampede, compression, and chunking protection.
```

### 12. Cache Clearing Command

```bash
# Clear a specific cache tag or key
php artisan atomic-cache:clear users

# Clear all atomic cache entries (prompts for confirmation in production)
php artisan atomic-cache:clear --all

# Clear all atomic cache entries non-interactively (e.g. in CI or deployment scripts)
php artisan atomic-cache:clear --all --force
```

> [!WARNING]
> `--all` executes `flush()` on the configured cache store. If your cache store is shared with sessions or queue jobs, invalidate specific tags (e.g. `users`, `products`) to avoid clearing unrelated application data.

---

### 13. Eloquent & DB Query Macros (Zero-Key Caching) ⚡

Cache database queries directly off the Eloquent or Query Builder without writing or remembering cache key strings:

```php
use App\Models\Product;

// 1. Zero-Key Mode: auto-generates a unique key based on SQL + bindings!
// Automatically tagged with 'products' and Product::class for auto-invalidation
$featured = Product::where('status', 'active')->where('featured', 1)->atomicCache(3600);

// 2. Custom Key Mode (Hybrid freedom):
$bestSellers = Product::where('sales_count', '>', 100)->atomicCache('bestsellers', 7200);

// 3. Single record caching:
$product = Product::where('slug', $slug)->atomicCacheFirst(3600);

// 4. Works on query relationships & DB facade too:
$userPosts = $user->posts()->where('published', 1)->atomicCache();
$stats = DB::table('orders')->where('status', 'completed')->atomicCache(1800);
```

#### Why it's bulletproof:
- **Zero Key Collision:** Generates deterministic fingerprint `products:query:sha256(SQL + Bindings + Model + EagerLoads)`. If query parameters or eager relations differ, keys are isolated.
- **Automatic Join Tagging:** When queries join multiple tables (e.g. `users` join `orders`), AtomicCache automatically attaches both table tags, invalidating the cached result whenever either table changes. *(Note: Standard `join()` and `leftJoin()` methods are detected automatically; subqueries and `joinRaw()` should have additional tags passed explicitly in the `$tags` parameter).*
- **Zero Stale Cache:** Every query is automatically tagged with the model's table. When any `Product::create()`, `$product->update()`, `$product->decrement()`, or `$product->delete()` occurs, `AutoClearsCache` automatically wipes these queries!

---

### 14. Type-Safe Model Tags

Avoid typo errors and get full IDE autocomplete by passing Model classes or instances directly into `tags()`:

```php
use App\Models\Product;
use App\Models\Category;

// Passing Model classes gives you full IDE autocomplete & refactoring safety:
AtomicCache::tags([Product::class, Category::class])
    ->remember('featured_catalog', 3600, fn() => ...);

// Passing model instances automatically tags both "products:12" and "products":
AtomicCache::tags([$product])->remember(...);

// Supports numeric IDs and nested tag arrays seamlessly:
AtomicCache::tags(['orders', 42, ['vip', 'eu']])->remember(...);

// Invalidation also supports type-safe classes & IDs:
AtomicCache::clearTags([Product::class, 42]);
```

---

### 15. Model Audit & Invalidation Inspector

Inspect your entire application's models, verify cache invalidation tags, and check architectural health with the built-in doctor command:

```bash
# Run full model audit
php artisan atomic-cache:check

# Filter by status: active, recommended, or exempt
php artisan atomic-cache:check --filter=recommended

# Export machine-readable report for CI/CD pipelines
php artisan atomic-cache:check --json
```

```text
Atomic Cache Architecture Audit
Target Path: /var/www/html/app/Models

+-----------------------------+-------------+-----------------------+------------------------------------------+
| Model                       | Status      | Primary Invalidation  | Tags / Notes                             |
+-----------------------------+-------------+-----------------------+------------------------------------------+
| App\Models\Product          | Active      | products, products:id | product_categories, stores:{id}:products |
| App\Models\User             | Active      | users, users:{id}     | None                                     |
| App\Models\Plan             | Recommended | N/A                   | Domain model (Candidate for AutoClears)  |
| App\Models\Message          | Exempt      | N/A                   | Real-time Stream (Uncached by design)    |
| App\Models\WalkRoute        | Exempt      | N/A                   | High-Frequency Stream (Uncached)         |
+-----------------------------+-------------+-----------------------+------------------------------------------+

+-------------------------------------------------------------------------+
| Summary: 39 models scanned                                              |
| • Active (AutoClearsCache): 18                                          |
| • Exempt (Telemetry / Ephemeral): 16                                    |
| • Recommended: 5                                                        |
|                                                                         |
| Architecture Health Score: 87% (Good)                                   |
+-------------------------------------------------------------------------+

💡 Invalidation Architecture Guidance (1 suggested rule):

  • App\Models\Order:
    BelongsTo Store detected via store(), but parent cache is not linked.
    👉 Quick Fix: Add protected array $cacheParents = ['store']; to Order
    ✨ Auto-invalidates: "stores:{$this->store_id}:orders"
```

---

## 🧩 Eloquent Model Lifecycle (`AutoClearsCache`)

Add the `AutoClearsCache` trait to any Eloquent model. Whenever a record is created, updated, or deleted, its cache tags are automatically flushed **only after the active database transaction successfully commits** (`DB::afterCommit`):

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class User extends Model
{
    use AutoClearsCache;

    // Optional: automatically invalidate related cache tags when a user changes
    protected array $relatedCacheTags = ['dashboard', 'admin_stats'];
}
```

When `$user->save()`, `$user->update()`, `$user->decrement()`, `$user->increment()`, or `$user->delete()` executes:
1. `AutoClearsCache` listens to model `created`, `updated`, `deleted`, and `restored` events.
2. Defers invalidation to `DB::afterCommit` (if transaction rolls back, cache is untouched).
3. Automatically invalidates single item tag: `users:{id}`.
4. Automatically invalidates collection tag: `users`.
5. Automatically invalidates related tags: `dashboard`, `admin_stats`.
*(Note: Direct mass database queries that bypass Eloquent models entirely like `DB::table('users')->decrement(...)` or `User::where(...)->update(...)` do not fire model events; call `AtomicCache::clearTags('users')` for those).*

### Declarative Parent Invalidation (`$cacheParents`)

Just like Laravel's native `protected $touches = ['store'];`, you can declare parent relationships to automatically invalidate parent-scoped cache tags whenever a child record is created, updated, soft-deleted, or restored:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class Order extends Model
{
    use AutoClearsCache;

    // Declarative Parent Invalidation (supports $cacheParents, or aliases $cacheTouches / $clearsRelations)
    protected array $cacheParents = ['store'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
```

#### What happens under the hood:
When an order in Store #5 is created or updated:
- Automatically flushes child item tag: `orders:{id}`
- Automatically flushes child collection tag: `orders`
- Automatically flushes parent child-scoped tag: `stores:5:orders`
- Automatically flushes parent umbrella tag: `stores:5`
- **Zero Cache Thrashing:** Leaving Store #1..#4 and unaffected models untouched!
- **Dirty Foreign Key Protection:** If an order is moved from Store 1 to Store 2 (`$order->store_id = 2`), AtomicCache invalidates caches for **both** the old store (1) and the new store (2), completely preventing ghost data bugs!

### 🏷️ Cache Key vs Cache Tag Cheat-Sheet

| What are you caching? | Service Cache Tag | Model Declaration |
| :--- | :--- | :--- |
| **All Products List** | `tags: ['products']` | `Product` $\rightarrow$ `use AutoClearsCache;` *(automatic)* |
| **Single Product by ID** | `tags: ['products:5', 'products']` | `Product` $\rightarrow$ `use AutoClearsCache;` *(automatic)* |
| **Store's Orders List** | `tags: ['stores:5:orders', 'stores:5']` | `Order` $\rightarrow$ `protected array $cacheParents = ['store'];` |
| **Category's Products** | `tags: ['categories:3:products', 'categories:3']` | `Product` $\rightarrow$ `protected array $cacheParents = ['category'];` |

> **Rule of Thumb:**
> - **Cache Key:** The unique address of a specific box of data (includes method, page number, filters).
> - **Cache Tag:** The label on the box used for grouped mass-invalidation (never includes page numbers or filters). Format: `{parent}:{parent_id}:{child}`.

---

## 🏗️ Service & Repository Layer (`HasAtomicCache`)

Standardize your caching architecture across services with the `HasAtomicCache` trait:

```php
namespace App\Services;

use App\Models\User;
use KaziOmar\AtomicCache\Traits\HasAtomicCache;

class UserService
{
    use HasAtomicCache;

    // Optional service-level configuration properties:
    protected bool $cachingEnabled = true; // Set to false to bypass cache (useful in tests/debugging)
    protected int $cacheTTL = 7200;        // Custom default TTL in seconds for this service
    protected bool $cachePerUser = false;  // Scope all operations to the authenticated user ID
    protected string $cacheResource = 'users'; // Base resource tag (defaults to table/plural class name)

    public function find(int $id)
    {
        // Idiomatic single-item remember (alias: cacheItem)
        return $this->rememberItem($id, fn() => User::findOrFail($id));
    }

    public function getActiveUsers()
    {
        // Idiomatic list remember (alias: cacheCollection or rememberList)
        return $this->rememberCollection('active', fn() => User::where('active', 1)->get());
    }

    public function update(int $id, array $data)
    {
        $user = User::findOrFail($id);
        $user->update($data);

        // Standard Laravel terminology: forget single item & flush list
        $this->forgetItem($id);       // alias: clearItemCache()
        $this->flushCollection();     // alias: clearCollectionCache()

        return $user;
    }
}
```

### Service Configuration Properties

You can customize caching per service by declaring any of these optional properties:

| Property | Type | Default | Purpose |
| :--- | :--- | :--- | :--- |
| **`$cachingEnabled`** | `bool` | `true` | **Service Cache Toggle.** Set to `false` during automated testing or debugging to bypass caching completely and always fetch fresh database records. |
| **`$cacheTTL`** | `int` | `3600` | **Service-Level Expiry.** Defines the default cache lifetime in seconds for this specific service without overriding global config. |
| **`$cachePerUser`** | `bool` | `false` | **Automatic User Isolation.** When set to `true`, all cache keys generated in this service are automatically prefixed with the logged-in user's ID (`auth()->id()`), preventing user data leaks. |
| **`$allowedQueryParams`** | `array` | `[]` | **Security Whitelist (DoS Mitigation).** When specified (e.g. `['page', 'sort', 'category']`), *only* these query parameters are hashed into the cache key. Any unexpected query parameters (`?attacker=1`) are discarded, preventing Cache Pollution and Redis memory exhaustion attacks. |
| **`$cacheResource`** | `string` | *(auto)* | **Resource Tag & Namespace.** Specifies the cache tag and key prefix (e.g. `'users'`, `'orders'`). Defaults automatically to the model's table or pluralized class name (`UserService` -> `'users'`). |

### Safe Argument Normalization
If a service method receives complex arguments (e.g. `Closure`, Eloquent `Model`, or `Request`), `HasAtomicCache` safely converts them to unique hashes/identifiers before serialization. You will **never** encounter `Exception: Serialization of 'Closure' is not allowed`.

### Automatic Pagination & Filter Isolation (With Smart Tracking Sanitization)
When calling `cache()` or `rememberCollection()`, the trait automatically inspects `request()->query()`:
- **Clean Segmentation:** Request `GET /users?page=1` and `GET /users?page=2` are automatically stored under separate, isolated cache keys.
- **Cache Fragmentation Prevention (Cloudflare/CDN Style):** Common ad/analytics tracking parameters (`utm_*`, `fbclid`, `gclid`, `ttclid`, `msclkid`, `mc_cid`, `ref`, etc.) are automatically stripped before generating cache keys. When 10,000 visitors arrive from Facebook or Google Ads with unique `?fbclid=...` strings, they all share the exact same cached payload instead of polluting your cache memory with 10,000 duplicate entries!

You can customize or extend ignored query parameters in `config/atomic-cache.php` or by defining `protected array $ignoredQueryParams = ['custom_tracker'];` on your service class.

### HTTP Query Limits (Cache Poisoning & DoS Protection)
To prevent hash collision attacks, query string pollution, and memory exhaustion, Atomic Cache enforces strict request query limits:
- **`max_params` (Default: 20):** Requests with more than 20 query parameters automatically bypass the cache and run directly against the database.
- **`max_bytes` (Default: 2,048 bytes):** Requests with query string payloads exceeding 2KB cleanly bypass caching.

### Optimistic Concurrency Control (OCC Tag Invalidation)
When computing long-running closures in `remember()`, race conditions can occur if another process invalidates tags mid-flight (e.g. an admin updates inventory while a heavy 3-second report is calculating). 

Atomic Cache enforces **Optimistic Concurrency Control (OCC)**:
1. Records the tag namespace/version identifier immediately *before* invoking the closure.
2. Checks the tag version again *after* computation completes.
3. If tags were flushed or updated while the closure ran, the stale calculated write is safely **discarded** instead of repopulating expired cache keys.

---

## 🏪 Real-World Example: E-Commerce Store

Here is how a real-world e-commerce application uses Laravel Atomic Cache to handle high-traffic bursts, inventory changes, and large catalogs effortlessly:

### 1. The Eloquent Model
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class Product extends Model
{
    use AutoClearsCache;

    // Optional: Invalidate related tags when a product changes
    protected array $relatedCacheTags = ['homepage_featured'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
```

### 2. The Storefront Controller (Zero-Key Query Caching)
```php
namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function index(Request $request)
    {
        // Zero-Key Query Caching: auto-generates sha256 hash from compiled SQL + bindings
        // Automatically tags with 'products' and Product::class for instantaneous invalidation on DB commit
        $catalog = Product::where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->atomicCache(3600);

        return view('shop.index', compact('catalog'));
    }

    public function show(string $slug)
    {
        // First record cached cleanly with zero keys
        $product = Product::where('slug', $slug)->atomicCacheFirst(3600);

        return view('shop.show', compact('product'));
    }
}
```

### 3. The Order Checkout / Stock Update (Transaction-Safe Invalidation)
```php
namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function checkout(array $cartItems)
    {
        DB::transaction(function () use ($cartItems) {
            foreach ($cartItems as $item) {
                $product = Product::findOrFail($item['product_id']);
                $product->decrement('stock', $item['quantity']);

                // ⚡ Notice: Because decrement() updates the model, AutoClearsCache
                // queues the cache flush callback to run strictly inside DB::afterCommit().
                // If payment fails and the transaction rolls back, your cache remains 100% UNTOUCHED!
            }
        });

        // ✅ Once DB transaction successfully commits, all Product catalog caches
        // and single-item caches are instantly and atomically invalidated!
    }
}
```

### 4. Heavy Analytics / Black Friday Protection
```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

// During sudden flash sale traffic surges, only 1 worker computes this heavy query,
// while 5,000 concurrent shoppers wait a fraction of a second and read the cached response:
$flashSaleStats = AtomicCache::rememberWithLock('flash_sale_stats', 1800, function () {
    return HeavyReportingService::calculateDiscountMargins();
}, lockSeconds: 10, waitSeconds: 5);
```

---

## ⚙️ Configuration Reference

Publish `config/atomic-cache.php` to customize package behavior:

```php
return [
    // Master switch to enable/disable caching (useful in local tests)
    'enabled' => env('ATOMIC_CACHE_ENABLED', true),

    // Default TTL in seconds (1 hour)
    'default_ttl' => env('ATOMIC_CACHE_DEFAULT_TTL', 3600),

    // Specific cache store (null = default Laravel cache store)
    'store' => env('ATOMIC_CACHE_STORE', null),

    // Global key namespace prefix
    'prefix' => env('ATOMIC_CACHE_PREFIX', 'atomic'),

    // Stampede distributed locking settings
    'stampede' => [
        'enabled' => env('ATOMIC_CACHE_STAMPEDE_ENABLED', true),
        'lock_seconds' => env('ATOMIC_CACHE_LOCK_SECONDS', 10),
        'wait_seconds' => env('ATOMIC_CACHE_WAIT_SECONDS', 5),
    ],

    // Automatic Gzip payload compression
    'compression' => [
        'enabled' => env('ATOMIC_CACHE_COMPRESSION_ENABLED', true),
        'threshold' => env('ATOMIC_CACHE_COMPRESSION_THRESHOLD', 51200), // 50 KB
        'level' => env('ATOMIC_CACHE_COMPRESSION_LEVEL', 6),
    ],

    // Transparent chunking for large datasets & collections
    'chunking' => [
        'enabled' => env('ATOMIC_CACHE_CHUNKING_ENABLED', true),
        'threshold' => env('ATOMIC_CACHE_CHUNKING_THRESHOLD', 1000), // items threshold
        'chunk_size' => env('ATOMIC_CACHE_CHUNK_SIZE', 1000), // items per chunk
        'max_bytes' => env('ATOMIC_CACHE_CHUNK_MAX_BYTES', 800000), // dynamic byte size ceiling (800KB)
        'clean_on_put' => env('ATOMIC_CACHE_CHUNKING_CLEAN_ON_PUT', false), // purge prior chunks on non-chunked put
    ],

    // Write deduplication (skips compression & chunk re-splitting if identical content is cached)
    'deduplication' => [
        'enabled' => env('ATOMIC_CACHE_DEDUPLICATION_ENABLED', false),
    ],

    // Self-healing from corrupted cache payloads
    'self_healing' => [
        'enabled' => env('ATOMIC_CACHE_SELF_HEALING', true),
        'log_corrupted' => env('ATOMIC_CACHE_LOG_CORRUPTED', true),
    ],

    // Random variance on expiry time
    'jitter' => [
        'enabled' => env('ATOMIC_CACHE_JITTER_ENABLED', false),
        'percentage' => env('ATOMIC_CACHE_JITTER_PERCENTAGE', 5), // ±5%
    ],

    // Marketing/analytics parameters stripped from request query strings
    'ignored_query_parameters' => [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'dclid', 'wbraid', 'gbraid', 'ttclid', 'msclkid',
        'mc_cid', 'mc_eid', '_ga', '_gl', '_gid', 'ref',
    ],

    // HTTP Query Limits (Cache Poisoning & DoS Protection)
    'query_limits' => [
        'max_params' => env('ATOMIC_CACHE_QUERY_MAX_PARAMS', 20),
        'max_bytes' => env('ATOMIC_CACHE_QUERY_MAX_BYTES', 2048),
    ],
];
```

### Environment Variables (.env)

| Key | Default | Description |
| :--- | :--- | :--- |
| `ATOMIC_CACHE_ENABLED` | `true` | Enable or disable atomic caching globally |
| `ATOMIC_CACHE_DEFAULT_TTL` | `3600` | Default Time-To-Live in seconds |
| `ATOMIC_CACHE_STORE` | `null` | Cache driver store to use (`redis`, `file`, etc.) |
| `ATOMIC_CACHE_PREFIX` | `atomic` | Key namespace prefix |
| `ATOMIC_CACHE_STAMPEDE_ENABLED` | `true` | Enable atomic locking by default |
| `ATOMIC_CACHE_LOCK_SECONDS` | `10` | Maximum lock duration during computation |
| `ATOMIC_CACHE_WAIT_SECONDS` | `5` | Maximum wait time for concurrent requests |
| `ATOMIC_CACHE_COMPRESSION_ENABLED`| `true` | Auto-compress payloads exceeding threshold |
| `ATOMIC_CACHE_COMPRESSION_THRESHOLD`| `51200` | Compression threshold in bytes (50 KB) |
| `ATOMIC_CACHE_CHUNKING_ENABLED` | `true` | Auto-partition large datasets into safe chunk keys |
| `ATOMIC_CACHE_CHUNKING_THRESHOLD` | `1000` | Minimum item count required to trigger chunking |
| `ATOMIC_CACHE_CHUNK_SIZE` | `1000` | Item count per partitioned chunk |
| `ATOMIC_CACHE_CHUNK_MAX_BYTES` | `800000` | Dynamic chunk size threshold (under 800 KB for Memcached) |
| `ATOMIC_CACHE_CHUNKING_CLEAN_ON_PUT` | `false` | When true, non-chunked put purges prior chunk keys. Defaults to `false` for optimal write performance (no extra GET); orphan chunks expire cleanly on TTL. |
| `ATOMIC_CACHE_DEDUPLICATION_ENABLED`| `false` | Opt-in DNA hashing: skips compression and chunk re-splitting if identical content is cached |
| `ATOMIC_CACHE_JITTER_ENABLED` | `false` | Enable TTL variance globally |
| `ATOMIC_CACHE_JITTER_PERCENTAGE` | `5` | Jitter variance percentage (±5%) |
| `ATOMIC_CACHE_QUERY_MAX_PARAMS` | `20` | Max allowed sanitized HTTP query parameters before bypassing cache |
| `ATOMIC_CACHE_QUERY_MAX_BYTES` | `2048` | Max allowed serialized query string size before bypassing cache |

---

## 🧪 Testing

Run the test suite using PHPUnit:

```bash
composer test
```

---

## 👨‍💻 Credits

- **Kazi Omar Faruk** ([@kaziomarsh24](https://github.com/kaziomarsh24))

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
