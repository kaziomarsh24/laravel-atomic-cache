# Laravel Atomic Cache ⚡

[![Run Tests](https://github.com/kaziomarsh24/laravel-atomic-cache/actions/workflows/run-tests.yml/badge.svg)](https://github.com/kaziomarsh24/laravel-atomic-cache/actions)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20to%208.6-777BB4.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/thekaziomar/laravel-atomic-cache.svg?style=flat-square)](https://packagist.org/packages/thekaziomar/laravel-atomic-cache)

A high-performance, stampede-proof caching package for Laravel. Designed for modern web applications handling concurrent bursts, large payloads, multi-table model invalidations, and non-Redis environments.

```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

// Standard caching with auto-compression (>50KB) & self-healing
$users = AtomicCache::remember('users.active', 3600, fn() => User::where('active', 1)->get());

// Cache stampede protection (atomic lock so 1000 concurrent requests only run 1 query)
$report = AtomicCache::rememberWithLock('monthly_report', fn() => Report::generate());
```

---

## 📑 Table of Contents

- [Why Laravel Atomic Cache?](#why-laravel-atomic-cache)
- [Requirements](#requirements)
- [Installation](#installation)
- [Core Features & Usage](#core-features--usage)
  - [1. Basic Caching](#1-basic-caching)
  - [2. Stampede Protection (rememberWithLock)](#2-stampede-protection-rememberwithlock)
  - [3. Auto Payload Compression (Gzip)](#3-auto-payload-compression-gzip)
  - [4. Virtual Tagging Engine (File & Database Fallback)](#4-virtual-tagging-engine-file--database-fallback)
  - [5. Cache TTL Jitter (Thundering Herd Prevention)](#5-cache-ttl-jitter-thundering-herd-prevention)
  - [6. User-Scoped Caching (rememberForUser)](#6-user-scoped-caching-rememberforuser)
  - [7. Fluent Chainable Builder](#7-fluent-chainable-builder)
  - [8. Blade Fragment Caching](#8-blade-fragment-caching)
  - [9. Artisan CLI Command](#9-artisan-cli-command)
- [Eloquent Model Lifecycle (AutoClearsCache)](#eloquent-model-lifecycle-autoclearscache)
- [Service & Repository Layer (HasAtomicCache)](#service--repository-layer-hasatomiccache)
  - [Safe Argument Normalization](#safe-argument-normalization)
  - [Automatic Pagination & Filter Isolation](#automatic-pagination--filter-isolation)
- [Configuration Reference](#configuration-reference)
- [Testing](#testing)
- [Credits & License](#credits--license)

---

## 💡 Why Laravel Atomic Cache?

| Feature | Standard Laravel Cache | Laravel Atomic Cache |
| :--- | :--- | :--- |
| **Stampede Protection** | ❌ All concurrent requests hit DB | ✅ **Atomic Lock** (1 worker computes, others wait & read) |
| **Large Payload Size** | ❌ Raw uncompressed serialization | ✅ **Auto Gzip** (>50KB cuts Redis RAM by ~70%) |
| **Driver Tag Support** | ❌ Crashes on File/Database stores | ✅ **Virtual Tagging** (works across all stores without Redis) |
| **Corrupted Data** | ❌ Throws crash exception to user | ✅ **Self-Healing** (cleans key, regenerates silently) |
| **Simultaneous Expiry** | ❌ All keys expire in same second | ✅ **TTL Jitter** (random variance prevents herd spikes) |
| **Model Invalidation** | ⚠️ Fragile observers before commit | ✅ **DB::afterCommit** (prevents transaction race conditions) |
| **Pagination Caching** | ⚠️ Page 1 and 2 collide unless manual | ✅ **Auto Query Hash** (page/filter automatically isolated) |

---

## ⚙️ Requirements

- **PHP:** `8.2`, `8.3`, `8.4`, `8.5`, or `8.6`
- **Laravel Framework:** `10.x`, `11.x`, or `12.x`
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

## 🚀 Core Features & Usage

### 1. Basic Caching

Works seamlessly like native Laravel cache, supporting flexible parameter ordering:

```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

// Both parameter orders are supported:
$posts = AtomicCache::remember('posts.all', 3600, fn() => Post::all());
$posts = AtomicCache::remember('posts.all', fn() => Post::all(), 3600);

// Global helper function
$posts = atomic_cache('posts.all', fn() => Post::all(), 3600);

// Standard get, put, has, pull, clear
AtomicCache::put('key', $value, 3600);
$val = AtomicCache::get('key', default: null);
$exists = AtomicCache::has('key');
$val = AtomicCache::pull('key'); // Retrieve and forget
AtomicCache::clear('key');       // Alias for forget()
```

---

### 2. Stampede Protection (`rememberWithLock`)

During sudden traffic surges (e.g. after a cache flush or key expiry), hundreds of requests hit your database simultaneously trying to recalculate the same data.

`rememberWithLock` acquires an atomic distributed lock. **Only one process executes the query**, while concurrent requests wait up to 5 seconds and read the freshly cached result:

```php
$analytics = AtomicCache::rememberWithLock('dashboard.stats', function () {
    return HeavyAnalyticsService::calculate();
}, ttl: 3600, lockSeconds: 10, waitSeconds: 5);
```

---

### 3. Auto Payload Compression (Gzip)

Large Eloquent collections or API responses can consume gigabytes of Redis/Memcached RAM. 

Whenever a cached payload exceeds **50 KB** (configurable), Atomic Cache automatically compresses it with `gzcompress`:
- Reduces Redis memory usage by **~70% to 80%**.
- Decompression is handled transparently on retrieval.
- If payload is smaller than 50 KB, it stores without compression overhead.

---

### 4. Virtual Tagging Engine (File & Database Fallback)

In Laravel, calling `Cache::tags(['users'])` on `file` or `database` cache drivers crashes with `BadMethodCallException: This cache store does not support tagging.`

Atomic Cache introduces a **Virtual Tag Versioning Engine**:
- On Redis / Memcached: Uses native high-performance driver tags.
- On File / Database / SQLite / Array: Tracks atomic version counters per tag (e.g. `_tag_v:users = 1`).
- When `clearTags(['users'])` is called, it increments the tag version to `2`.
- Subsequent reads automatically experience a cache miss! **Tag invalidation works in local development without Redis!**

```php
// Clear a single model item: flushes tag "users:42"
AtomicCache::clearItem('users', 42);

// Clear an entire collection: flushes tag "users"
AtomicCache::clearCollection('users');

// Clear custom tags
AtomicCache::clearTags(['dashboard', 'reports']);
```

---

### 5. Cache TTL Jitter (Thundering Herd Prevention)

If you cache 1,000 keys for 3,600 seconds, they will all expire at the exact same microsecond, causing a sudden CPU and database spike.

`withJitter` adds a small random variance (e.g. ±5%) to the TTL:

```php
// TTL of 3600s will randomly vary between 3420s and 3780s
AtomicCache::withJitter()->remember('feed.popular', fn() => getFeed(), 3600);

// Or pass an explicit variance (e.g. 10%)
AtomicCache::withJitter(0.10)->remember('feed.popular', fn() => getFeed(), 3600);
```

---

### 6. User-Scoped Caching (`rememberForUser`)

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

### 7. Fluent Chainable Builder

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

### 8. Blade Fragment Caching

Cache expensive HTML blocks, navigation bars, or sidebar widgets directly in Blade templates:

```blade
@atomicCache('sidebar_categories', 3600)
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

### 9. Artisan CLI Command

Manage and clear cache tags or keys from terminal, cron jobs, or CI/CD deployment scripts:

```bash
# Clear a specific cache tag or key
php artisan atomic-cache:clear users

# Clear multiple tags
php artisan atomic-cache:clear dashboard

# Clear all atomic cache entries
php artisan atomic-cache:clear --all
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

When `$user->save()` or `$user->delete()` executes:
1. Waits for `DB::commit()` (if transaction rolls back, cache is untouched).
2. Automatically invalidates single item tag: `users:{id}`.
3. Automatically invalidates collection tag: `users`.
4. Automatically invalidates related tags: `dashboard`, `admin_stats`.

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

    // Optional: override default resource name (defaults to table/plural class name)
    protected string $cacheResource = 'users';

    public function find(int $id)
    {
        return $this->cacheItem($id, fn() => User::findOrFail($id));
    }

    public function getActiveUsers()
    {
        return $this->cacheCollection('active', fn() => User::where('active', 1)->get());
    }

    public function update(int $id, array $data)
    {
        $user = User::findOrFail($id);
        $user->update($data);

        // Clear both single item and list caches
        $this->clearItemCache($id);
        $this->clearCollectionCache();

        return $user;
    }
}
```

### Safe Argument Normalization
If a service method receives complex arguments (e.g. `Closure`, Eloquent `Model`, or `Request`), `HasAtomicCache` safely converts them to unique hashes/identifiers before serialization. You will **never** encounter `Exception: Serialization of 'Closure' is not allowed`.

### Automatic Pagination & Filter Isolation
When calling `cache()` or `cacheCollection()`, the trait automatically inspects `request()->query()`.
- Request `GET /users?page=1` and `GET /users?page=2` are automatically stored under separate, isolated cache keys.
- Search filters like `?filter=active&sort=desc` automatically receive independent cache entries.

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
| `ATOMIC_CACHE_JITTER_ENABLED` | `false` | Enable TTL variance globally |
| `ATOMIC_CACHE_JITTER_PERCENTAGE` | `5` | Jitter variance percentage (±5%) |

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
