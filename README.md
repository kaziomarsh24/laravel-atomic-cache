# Laravel Atomic Cache

A simple, stampede-proof caching package for Laravel with automatic compression and clean model invalidation.

```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

// Standard caching with auto-compression
$users = AtomicCache::remember('users.active', fn() => User::where('active', 1)->get());

// Cache stampede protection (atomic lock so heavy queries don't hit DB concurrently)
$report = AtomicCache::rememberWithLock('monthly_report', fn() => Report::generate());
```

---

## Why use this?

Laravel's default cache works great, but in real-world apps you often run into a few common problems:

1. **Cache Stampede:** When a popular cache key expires, dozens of requests hit your database at the exact same second trying to recalculate it.
2. **Huge Redis Memory Usage:** Large collections or API responses take up a ton of RAM if stored uncompressed.
3. **Broken Tag Support:** If you ever switch from Redis to Database or File driver, any `Cache::tags()` call immediately crashes with an exception.
4. **Stale Cache on Model Changes:** Manually clearing cache across controllers and observers easily leads to race conditions.

This package wraps standard cache operations with:
- **Atomic locks** to prevent stampedes (only 1 process runs the query, other requests wait for the result).
- **Auto Gzip compression** on payloads over 50 KB (saving ~70% memory).
- **Safe tagging** that works on Redis and won't crash on File or Database drivers.
- **Model auto-invalidation** that waits for the database transaction to commit before flushing tags.

---

## Installation

You can install the package via composer:

```bash
composer require thekaziomar/laravel-atomic-cache
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="atomic-cache-config"
```

---

## Usage

### 1. Basic Caching

Works like the normal Laravel cache helper, but automatically handles compression for large arrays/objects:

```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

$posts = AtomicCache::remember('posts.all', function () {
    return Post::all();
}, ttl: 3600);
```

You can also use the global helper:

```php
$posts = atomic_cache('posts.all', fn() => Post::all(), 3600);
```

### 2. Preventing Cache Stampede (`rememberWithLock`)

Use `rememberWithLock` on heavy queries or dashboards. If the cache is empty or expired, only the first request runs the callback while concurrent requests wait up to 5 seconds to read the newly cached data:

```php
$analytics = AtomicCache::rememberWithLock('dashboard.stats', function () {
    return Analytics::calculate();
}, ttl: 3600);
```

### 3. Per-User Caching (`rememberForUser`)

Automatically prefixes the cache key with the authenticated user ID (or a passed ID):

```php
// Cached for auth()->id()
$settings = AtomicCache::rememberForUser('settings', function () {
    return Auth::user()->settings;
});

// Or pass an explicit user ID
$profile = AtomicCache::rememberForUser('profile', fn() => User::find(10), userId: 10);
```

### 4. Cache TTL Jitter (Thundering Herd Protection)

Prevent multiple keys created at the same time from expiring in the exact same microsecond:

```php
// Adds a small random variance (e.g. ±5%) to the TTL
AtomicCache::withJitter()->remember('popular_data', fn() => heavyQuery(), 3600);
```

### 5. Blade Fragment Caching (`@atomicCache`)

Cache expensive HTML blocks or navigation components directly in Blade templates:

```blade
@atomicCache('sidebar_categories', 3600)
    <div class="sidebar">
        @foreach(\App\Models\Category::all() as $category)
            <p>{{ $category->name }}</p>
        @endforeach
    </div>
@endAtomicCache
```

### 6. Clearing Cache & Artisan Command

You can clear individual keys, model items, or tags:

```php
// Clear a single key
AtomicCache::clear('posts.all');

// Clear a specific model item (flushes tag "posts:5")
AtomicCache::clearItem('posts', 5);

// Clear an entire collection tag (flushes tag "posts")
AtomicCache::clearCollection('posts');

// Clear multiple tags
AtomicCache::clearTags(['posts', 'comments']);
```

Or clear from terminal using the built-in Artisan command:

```bash
# Clear a specific tag or key
php artisan atomic-cache:clear users

# Clear all cache entries
php artisan atomic-cache:clear --all
```

---

## Auto-Clearing Cache on Eloquent Models

Add the `AutoClearsCache` trait to your models. Whenever a model is saved or deleted, its related cache tags are cleared **only after the database transaction successfully commits**:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class Post extends Model
{
    use AutoClearsCache;

    // Optional: also clear related tags when a post changes
    protected array $relatedCacheTags = ['home_feed', 'trending_posts'];
}
```

When `$post->save()` runs:
- Clears item tag: `posts:{id}`
- Clears list tag: `posts`
- Clears extra tags: `home_feed`, `trending_posts`

---

## Using in Services / Repositories

Add `HasAtomicCache` to clean up your service layer:

```php
namespace App\Services;

use App\Models\User;
use KaziOmar\AtomicCache\Traits\HasAtomicCache;

class UserService
{
    use HasAtomicCache;

    public function find(int $id)
    {
        return $this->cacheItem($id, fn() => User::findOrFail($id));
    }

    public function getActive()
    {
        return $this->cacheCollection('active', fn() => User::where('active', 1)->get());
    }

    public function update(int $id, array $data)
    {
        $user = User::findOrFail($id);
        $user->update($data);

        $this->clearItemCache($id);
        $this->clearCollectionCache();

        return $user;
    }
}
```

---

## Configuration

If you published `config/atomic-cache.php`, you can adjust defaults:

```php
return [
    // Enable or disable caching (useful to turn off during testing)
    'enabled' => env('ATOMIC_CACHE_ENABLED', true),

    // Default TTL in seconds (1 hour)
    'default_ttl' => env('ATOMIC_CACHE_DEFAULT_TTL', 3600),

    // Lock timings for stampede protection
    'stampede' => [
        'enabled' => env('ATOMIC_CACHE_STAMPEDE_ENABLED', true),
        'lock_seconds' => 10,
        'wait_seconds' => 5,
    ],

    // Automatic compression settings
    'compression' => [
        'enabled' => env('ATOMIC_CACHE_COMPRESSION_ENABLED', true),
        'threshold' => 51200, // 50 KB
        'level' => 6,
    ],

    // If corrupted cache data is found, purge it and re-calculate silently
    'self_healing' => [
        'enabled' => true,
        'log_corrupted' => true,
    ],
];
```

---

## Testing

```bash
composer test
```

## Credits

- **Kazi Omar Faruk** ([@kaziomarsh24](https://github.com/kaziomarsh24))

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
