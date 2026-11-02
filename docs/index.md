---
layout: home

hero:
  name: "Atomic Cache"
  text: "Stampede-proof caching and invalidation for Laravel."
  tagline: '<span class="hero-badge">v1.5 • Laravel 10, 11, 12 & 13</span><br>Atomic distributed locks to prevent dogpiling, zero-crash virtual tags for every cache store, and transaction-safe invalidation deferred to database commits.'
  image:
    src: /logo.svg
    alt: Laravel Atomic Cache
  actions:
    - theme: brand
      text: Get Started
      link: /guide/overview
    - theme: alt
      text: 10-Second Quickstart
      link: /guide/quickstart
    - theme: alt
      text: GitHub
      link: https://github.com/kaziomarsh24/laravel-atomic-cache

features:
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'
    title: Distributed Stampede Shield
    details: Stops dogpiling during traffic spikes. Only one worker computes the query while concurrent requests wait and read the cached result from memory.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>'
    title: Zero-Crash Virtual Tagging
    details: Calling tags on File or Database drivers crashes standard Laravel. Atomic Cache adds fast, version-based tag invalidation across all stores without requiring Redis.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>'
    title: Automatic Gzip Compression
    details: Payloads exceeding 50KB are compressed transparently with Gzip, significantly reducing memory usage on Redis and Memcached stores.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>'
    title: Transparent Chunking
    details: Large datasets with over 1,000 items are partitioned across driver-safe chunk keys, preventing single-threaded Redis freezes and Memcached 1MB limits.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1z"/></svg>'
    title: Transaction-Safe Invalidation
    details: Active database transactions automatically bypass cache writes to prevent dirty reads. Model invalidations run strictly on DB::afterCommit.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>'
    title: Optimistic Concurrency Control
    details: Prevents slow computations from overwriting newer database updates. Stale writes are safely dropped if tags change mid-calculation.
---

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-number">1 Query</div>
    <div class="stat-label">Dogpile Defense</div>
    <div class="stat-desc">Under 1,000 concurrent requests</div>
  </div>
  <div class="stat-card">
    <div class="stat-number">~80%</div>
    <div class="stat-label">RAM Reduction</div>
    <div class="stat-desc">Transparent Gzip compression</div>
  </div>
  <div class="stat-card">
    <div class="stat-number">0ms</div>
    <div class="stat-label">Tag Invalidation</div>
    <div class="stat-desc">Universal UUID version tokens</div>
  </div>
  <div class="stat-card">
    <div class="stat-number">100%</div>
    <div class="stat-label">Commit Guard</div>
    <div class="stat-desc">Zero ghost entries on rollback</div>
  </div>
</div>

## Common Usage Patterns

::: code-group

```php [Stampede-Proof Core Engine]
use App\Models\Report;
use KaziOmar\AtomicCache\Facades\AtomicCache;

// 1,000 concurrent requests execute only 1 database query:
$report = AtomicCache::tags(['reports', 'analytics'])
    ->rememberWithLock('annual_sales_2026', 86400, function () {
        return Report::generateAggregatedFinancials();
    }, lockSeconds: 15, waitSeconds: 5);
```

```php [Declarative Eloquent Models]
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class Product extends Model
{
    use AutoClearsCache;

    // Automatically flushes parent store caches on DB::afterCommit:
    protected array $cacheParents = ['store'];

    // Ignores high-frequency counter updates to prevent cache churn:
    protected array $cacheIgnoreColumns = ['view_count', 'meta'];
}
```

```php [Zero-Key Query Macros]
use App\Models\Product;

// Auto-generates sha256 hash from SQL + bindings + eager loads
// Tagged with 'products' for automatic invalidation on updates:
$featured = Product::where('status', 'active')
    ->where('featured', true)
    ->atomicCache(3600);

// Single record query:
$product = Product::where('slug', $slug)->atomicCacheFirst(3600);
```

```php [Service & Repository Layer]
namespace App\Services;

use App\Models\Product;
use KaziOmar\AtomicCache\Traits\HasAtomicCache;

class ProductService
{
    use HasAtomicCache;

    protected string $cacheResource = 'products';

    public function getCatalog()
    {
        // Automatically isolates page numbers and query filters:
        return $this->rememberCollection('catalog', fn() => Product::paginate(20));
    }
}
```

:::

## Architectural Comparison

| Feature | Standard Laravel Cache | Laravel Atomic Cache |
| :--- | :--- | :--- |
| **Stampede Protection** | No (All concurrent requests hit DB) | **Atomic Lock** (1 worker computes, others wait and read) |
| **Large Payload Size** | Raw uncompressed serialization | **Auto Gzip** (>50KB cuts Redis RAM by up to 70–80%) |
| **Mega Datasets** | Fails on 1MB Memcached or Redis freeze | **Transparent Chunking** (splits safely, preserves keys) |
| **Redundant Writes** | Every put hits storage and network | **Write Deduplication** (skips re-compression and chunk re-splitting) |
| **Driver Tag Support** | Crashes on File and Database stores | **Virtual Tagging** (works across all stores without Redis) |
| **Corrupted Data** | Throws crash exception to user | **Self-Healing** (cleans key, regenerates silently) |
| **Simultaneous Expiry** | All keys expire in the same second | **TTL Jitter** (random variance prevents herd spikes) |
| **Model Invalidation** | Observers fire before commit | **DB::afterCommit** (prevents transaction race conditions) |
| **Query Caching** | Manual key hashing required | **Zero-Key Macros** (auto SQL and eager load fingerprinting) |
| **Architecture Audit** | Manual DIY logging | **Artisan Doctor CLI** (`atomic-cache:check`) |
