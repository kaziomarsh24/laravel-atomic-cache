# Layer 1: The Core Caching Engine (`AtomicCache::`)

The `AtomicCache` facade provides manual, high-performance caching with distributed concurrency controls. It is completely self-contained and operates independently of model traits or macros.

---

### 1.1 Basic Caching

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

::: warning Failure Modes & Caveats
* **Passing a non-positive TTL (`0` or negative number):** Calling `AtomicCache::put('key', $value, 0)` will **not** cache the item; it immediately executes `forget('key')` and deletes the item.
* **Passing a past `DateTimeInterface`:** Passing a Carbon date in the past calculates a negative TTL and immediately deletes the key.
* **Un-serializable callback returns:** If your closure returns an open resource (such as a `curl` handle, file pointer, or `PDO` instance), PHP serialization fails and stores an empty or corrupted entry.
:::

---

### 1.2 Stampede Protection (`rememberWithLock`)

When an expensive cache item expires under high traffic, dozens or hundreds of concurrent requests simultaneously execute the heavy database query (known as the **Dogpiling** or **Cache Stampede** effect).

`rememberWithLock` acquires an atomic distributed lock via Laravel's `LockProvider`. **Only the first process calculates the value**, while all other requests wait up to `waitSeconds` (default 5s) and read the freshly computed cache from memory using **Double-Checked Locking**:

```php
$stats = AtomicCache::rememberWithLock('annual_sales_report', 86400, function () {
    return HeavyReportingService::calculate();
}, lockSeconds: 15, waitSeconds: 5);
```

::: warning Failure Modes & Caveats
* **Using a cache store without lock support:** Drivers that do not implement `Illuminate\Contracts\Cache\LockProvider` (or the `null` driver whose `NullStore` returns a dummy `NoLock`) do not provide distributed concurrency locking. The engine detects unsupported lock providers and bypasses locking, executing the callback directly. *(Laravel's `redis`, `memcached`, `database`, and `file` drivers all support locks natively).*
* **Calculation exceeding `lockSeconds`:** If computation takes 20 seconds but `lockSeconds` is set to 10 seconds, the lock expires prematurely while the first worker is still running, allowing waiting workers to enter and recompute. Always set `lockSeconds` higher than your maximum expected computation time.
* **`waitSeconds` too short:** If computation takes 4 seconds but `waitSeconds` is set to 1 second, waiting requests will time out waiting for the lock and fall back to executing the query themselves.
:::

---

### 1.3 Virtual Tagging Engine (Native & Non-Taggable Stores)

In standard Laravel, calling `Cache::tags(['products'])` on `file` or `database` cache drivers crashes with a fatal exception: `This cache store does not support tagging.`

Atomic Cache introduces a **Virtual Tag Versioning Engine**:
* On **Redis / Memcached / Array**: Uses native driver tags for high-speed tagging.
* On **File / Database / SQLite**: Embeds atomic UUID version tokens into cache keys (`{prefix}:{key}:vt:{hash}`).
* When `AtomicCache::clearTags(['products'])` is called, it replaces the virtual tag UUID in 0ms. All cached entries under that tag instantly experience a cache miss without requiring slow file directory traversals or heavy SQL deletes.

```php
// Tagging reads and writes (works identically on Redis, File, Database, or Array):
$catalog = AtomicCache::tags(['products', 'categories'])
    ->remember('catalog_active', 3600, fn() => Product::active()->get());

// Flushing all items under one or more tags:
AtomicCache::clearTags(['products']);
// Alias matching Laravel nomenclature:
AtomicCache::flushTags(['products', 'categories']);
```

::: warning Failure Modes & Caveats
* **Calling Laravel's native `Cache::tags()` on non-taggable stores:** Always call `AtomicCache::tags()`. Calling Laravel's built-in `Cache::tags()` directly will still crash on `file` or `database` drivers.
* **File driver disk accumulation:** On the `file` driver, virtual tags invalidate entries logically in 0ms (subsequent reads miss immediately). However, old serialized files remain on disk until their TTL expires or until `php artisan cache:clear` is run.
:::

---

### 1.4 Manual Cache Invalidation Methods

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

::: danger Shared Store Invalidation
Calling `AtomicCache::flush()` without active tags executes `flush()` on the underlying raw cache store. If your Redis store is shared with session or queue data, this will wipe active user sessions. Always use `clearTags()` or `clearCollection()` instead of `flush()`.
:::

---

### 1.5 Automatic Payload Compression (Gzip)

Large Eloquent collections or API responses consume megabytes of Redis/Memcached RAM. Whenever a cached payload exceeds **50 KB** (configurable), Atomic Cache automatically compresses it with `gzcompress`:

* Reduces Redis/Memcached memory footprint by **up to 70% to 80%** (actual savings depend on payload structure and entropy).
* Decompression is completely transparent upon retrieval.
* Payloads smaller than 50 KB are stored raw without compression CPU overhead.

::: info Compression Nuances
* **Missing `ext-zlib` extension:** If PHP is compiled without the `zlib` extension, compression is automatically skipped and data is saved uncompressed without crashing your application.
* **High-entropy binary data:** Payloads that are already compressed (such as JPEG images, PDF files, or zip archives) cannot be compressed further; Gzip provides negligible reduction for them.
:::

---

### 1.6 Transparent Chunking (Mega Datasets & Collections)

Storing 10,000+ items or multi-megabyte collections in a single cache entry freezes Redis single-threaded loops and violates Memcached's strict 1MB key limit.

Atomic Cache includes **Transparent Chunking**:
* Arrays and Collections exceeding 1,000 items (or 800 KB estimated payload) are automatically partitioned across driver-safe chunk keys (`{key}:__chunk__:{generation}:{i}`).
* **Generation UUIDs (Zero Torn Reads):** All chunks are published under a unique generation UUID before the manifest pointer updates atomically, preventing concurrent readers from reading partial/torn data.
* **Dynamic 800 KB Guard:** Dynamically calculates items per chunk so individual chunk payloads never exceed 800 KB, remaining Memcached-safe.
* **Key preservation:** Associative string keys and sparse numeric array indexes are preserved upon reassembly.
* **Collection restoration:** Instances of `Illuminate\Support\Collection` are seamlessly reconstituted.

```php
// Storing 20,000 items automatically splits into 20 safe chunks behind the scenes:
$data = AtomicCache::remember('large_export', 3600, fn() => ExportRecord::all());
```

::: warning Chunking Integrity
* **Disabling chunking with Memcached:** If you set `ATOMIC_CACHE_CHUNKING_ENABLED=false` in `.env` and attempt to cache a collection larger than 1MB on Memcached, Memcached will throw a payload size exception.
* **External key manipulation:** Manually deleting an individual `{key}:__chunk__:...` key in Redis will corrupt the chunk set. Atomic Cache's self-healing layer detects the missing chunk, logs a warning, and recomputes the original data safely.
:::

---

### 1.7 Cache TTL Jitter (Thundering Herd Variance)

If you cache 1,000 database items simultaneously with a fixed TTL of 3,600 seconds, all 1,000 keys expire at the exact same microsecond, causing an instant CPU spike (known as the **Thundering Herd** problem).

`withJitter()` adds a small random variance (e.g. ±5%) to the TTL:

```php
// TTL of 3600s will randomly vary between 3420s and 3780s:
AtomicCache::withJitter(0.05)->remember('popular_feed', 3600, fn() => ...);
```

::: info Clamped Variance
The engine clamps jitter percentages between 0% and 50% to prevent non-positive TTL calculations from deleting keys.
:::

---

### 1.8 User-Scoped Caching (`rememberForUser`)

Scopes the cache key to the currently authenticated user ID (`atomic:user:{id}:{key}`) or an explicit user ID:

```php
// Automatically scopes to auth()->id():
$userMenu = AtomicCache::rememberForUser('user_menu', 3600, fn() => ...);

// Explicit user ID scoping:
$dashboard = AtomicCache::rememberForUser('dashboard', 3600, fn() => ..., userId: 42);
```

::: warning Guest Fallback
If `rememberForUser()` is called when no user is logged in (`auth()->id() === null`), Atomic Cache falls back to the current session ID (`guest:{session_id}`). If sessions are disabled or uninitialized, it falls back to a global `guest` key. Do not use `rememberForUser` for guest-facing public content.
:::

---

### 1.9 Write Deduplication (Cache DNA)

When `ATOMIC_CACHE_DEDUPLICATION_ENABLED=true` is enabled in `.env`, Atomic Cache hashes the serialized payload (`Cache DNA`) before writing. If identical content is already cached with a valid remaining TTL, **it skips CPU-intensive Gzip re-compression and chunk re-splitting**, refreshing the existing TTL in storage.

::: tip Deterministic Payloads
If your cached array includes dynamic timestamps like `now()` or random tokens, every payload hash will differ, bypassing deduplication benefits. Ensure payloads are deterministic.
:::

---

### 1.10 Optimistic Concurrency Control (OCC)

If a heavy calculation takes 4 seconds, and during second 2 an admin updates the database (flushing the tag), writing the 4-second-old result to the cache at second 4 would overwrite the update with stale data.

Atomic Cache minimizes stale cache writes using Optimistic Concurrency Control (OCC). It captures universal UUID version tokens per tag before computing the value. If a tag is cleared while a closure is executing, the token mismatch triggers an OCC discard, safely dropping the stale write instead of repopulating the cache with outdated data.

On virtual tag drivers (File, Database), TOCTOU race conditions are closed completely by baking the pre-computation version hash into the storage key. On native taggable stores (Redis, Memcached), the stale write window is minimized to microsecond intervals.

::: warning OCC Nuances
* **Direct `Cache::tags([...])->flush()` bypass:** OCC relies on `AtomicCache::clearTags()`, `flushTags()`, or model events rotating the tag version token. If application code or a third-party package bypasses Atomic Cache and calls Laravel's native `Cache::tags([...])->flush()` directly, the native tags are flushed but Atomic Cache's version tokens are not rotated.
* **External non-PHP invalidation:** If an external service or raw Redis CLI command flushes Redis tags directly bypassing PHP, Atomic Cache cannot detect the external modification.
* **Redis `allkeys-lru` eviction:** OCC version tokens are stored with `forever()`. If Redis runs out of memory under an `allkeys-lru` policy, Redis may evict version token keys. If evicted, the next access generates a fresh UUID token. This safely forces a cache miss (preventing stale data), but can temporarily elevate cache miss rates until repopulated.
* **Write-heavy tables with broad tags:** On tables with continuous concurrent updates (e.g., streaming telemetry), OCC repeatedly discards stale writes. For high-frequency write tables, cache targeted single-item keys instead of broad table collections.
:::

---

### 1.11 Self-Healing Engine

If a cached payload is corrupted, truncated by memory limits, or missing a chunk, Atomic Cache catches the deserialization exception, logs the event, purges the broken key, and silently recomputes fresh data from the callback without crashing the user's HTTP request.

---

### 1.12 Transaction Guard (Active Database Transaction Bypass)

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

::: warning Testing Alert (`RefreshDatabase` & `DatabaseTransactions`)
If your feature tests use Laravel's `RefreshDatabase` or `DatabaseTransactions` traits, the entire test runs inside an uncommitted database transaction (`transactionLevel() === 1`).

Because `bypass_during_transactions` is enabled by default, cache `remember()` calls will **always bypass cache** during those tests!

To test cache hits inside transactional feature tests, disable the bypass in your `phpunit.xml`:

```xml
<env name="ATOMIC_CACHE_BYPASS_DURING_TRANSACTIONS" value="false"/>
```

or use `AtomicCache::withoutTransactionBypass()->remember(...)`.
:::
