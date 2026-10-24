# Laravel Atomic Cache ⚡

[![Latest Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/kaziomarsh24/laravel-atomic-cache)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20|%208.3%20|%208.4-777BB4.svg)](https://php.net)
[![Laravel Support](https://img.shields.io/badge/Laravel-10.x%20|%2011.x%20|%2012.x-FF2D20.svg)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A high-performance, stampede-proof, tag-aware caching engine for Laravel. Designed for modern web applications handling large payloads, concurrent bursts, and complex multi-table model invalidations.

---

## 🌟 Why Laravel Atomic Cache?

| Feature | Standard Laravel Cache | Laravel Atomic Cache |
| :--- | :--- | :--- |
| **Stampede Protection** | ❌ All concurrent requests hit DB | ✅ **Atomic lock** (single worker computes, others wait) |
| **Large Payload Size** | ❌ Raw uncompressed serialization | ✅ **Auto Gzip compression** (>50KB reduces Redis RAM by ~70%) |
| **Cache Tag Safety** | ❌ Crashes on File/Database stores | ✅ **Graceful fallback** without throwing exceptions |
| **Corrupted Data** | ❌ Throws crash exception to user | ✅ **Self-Healing** (cleans key, re-computes silently) |
| **Model Invalidation** | ❌ Manual or fragile observers | ✅ **Auto DB::afterCommit** hook (prevents race conditions) |
| **Tag Granularity** | ⚠️ Manually managed | ✅ Separates Single Item (`users:12`) vs Collection (`users`) |
| **Per-User Scoping** | ⚠️ Boilerplate `key . Auth::id()` | ✅ Built-in `rememberForUser()` |

---

## 📦 Installation

```bash
composer require kaziomarsh24/laravel-atomic-cache
```

Publish the optional configuration file:

```bash
php artisan vendor:publish --tag="atomic-cache-config"
```

---

## 🚀 Quick Start (Super Simple Function Names)

### 1. Basic Remember
Works just like Laravel's native cache, but with built-in compression and self-healing:

```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

$users = AtomicCache::remember('active_users', fn() => User::where('is_active', true)->get());
```

### 2. Stampede-Protected Remember (`rememberWithLock`)
When a key expires or is missing during high traffic, only **one** process runs the query while other requests wait up to 5 seconds and read the fresh result:

```php
$analytics = AtomicCache::rememberWithLock('dashboard_stats', function () {
    return HeavyReportService::generate();
}, ttl: 3600);
```

### 3. User-Scoped Cache (`rememberForUser`)
Automatically scopes the cache key to the currently authenticated user (or a custom user ID):

```php
// Cached specifically for auth()->id()
$notifications = AtomicCache::rememberForUser('user_menu', function () {
    return Auth::user()->menuPreferences();
});

// Or specify an explicit user ID
$profile = AtomicCache::rememberForUser('profile', fn() => User::find(42), userId: 42);
```

### 4. Tag-Aware Invalidation (`clearItem` & `clearCollection`)
Safe across all cache drivers. If Redis/Memcached is used, native tags are flushed. If File/Database driver is used, it handles it safely without crashing!

```php
// Clear a single item: tag "users:42"
AtomicCache::clearItem('users', 42);

// Clear the entire collection: tag "users"
AtomicCache::clearCollection('users');

// Clear custom tags
AtomicCache::clearTags(['dashboard', 'reports']);
```

---

## 🧩 Eloquent Model Auto-Invalidation (`AutoClearsCache`)

Add the `AutoClearsCache` trait to any Eloquent model. Whenever a record is created, updated, or deleted, its cache tags are automatically cleared **after the active database transaction commits** (preventing race conditions):

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class User extends Model
{
    use AutoClearsCache;

    // Optional: automatically clear related tags whenever a user changes
    protected array $relatedCacheTags = ['dashboard', 'admin_stats'];
}
```

Now, whenever `$user->update(...)` runs:
1. It waits for `DB::commit()`.
2. Automatically invalidates tag `users:42` (single item).
3. Automatically invalidates tag `users` (collection).
4. Automatically invalidates tag `dashboard` & `admin_stats`.

---

## 🏗️ Service / Repository Integration (`HasAtomicCache`)

Use `HasAtomicCache` in your services or repositories to standardize your caching logic with zero boilerplate:

```php
namespace App\Services;

use App\Models\User;
use KaziOmar\AtomicCache\Traits\HasAtomicCache;

class UserService
{
    use HasAtomicCache;

    // Optional: override default resource name (defaults to table/plural class name)
    protected string $cacheResource = 'users';

    public function findUser(int $id)
    {
        return $this->cacheItem($id, fn() => User::findOrFail($id));
    }

    public function getActiveUsers()
    {
        return $this->cacheCollection('active', fn() => User::where('is_active', true)->get());
    }

    public function updateUser(int $id, array $data)
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

---

## ⚙️ Configuration (`config/atomic-cache.php`)

```php
return [
    // Master switch to toggle caching (great for local tests)
    'enabled' => env('ATOMIC_CACHE_ENABLED', true),

    // Default TTL in seconds (1 hour)
    'default_ttl' => env('ATOMIC_CACHE_DEFAULT_TTL', 3600),

    // Specific cache store (null = default Laravel store)
    'store' => env('ATOMIC_CACHE_STORE', null),

    // Stampede distributed lock settings
    'stampede' => [
        'enabled' => env('ATOMIC_CACHE_STAMPEDE_ENABLED', true),
        'lock_seconds' => 10,
        'wait_seconds' => 5,
    ],

    // Automatic payload compression for items > 50KB
    'compression' => [
        'enabled' => env('ATOMIC_CACHE_COMPRESSION_ENABLED', true),
        'threshold' => 51200, // 50 KB
        'level' => 6,
    ],

    // Self-healing from corrupted cache data
    'self_healing' => [
        'enabled' => true,
        'log_corrupted' => true,
    ],
];
```

---

## 🧪 Global Helper Function

You can also use the handy `atomic_cache()` helper:

```php
// Quick remember
$stats = atomic_cache('daily_stats', fn() => calculate(), 3600);

// Fluent chaining
$data = atomic_cache()->tags(['orders'])->withLock()->remember('recent', fn() => ...);
```

---

## 📄 License

Laravel Atomic Cache is open-sourced software licensed under the [MIT license](LICENSE).
