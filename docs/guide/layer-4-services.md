# Layer 4: Service & Repository Layer (`HasAtomicCache`)

Standardize caching in your service, repository, or action classes by adding the `HasAtomicCache` trait.

---

### 4.1 Automatic Method-Level Caching (`$this->cache()`)

#### How it works:
Instead of manually inventing and maintaining custom cache key strings across your codebase, you can cache any service method directly by passing PHP's native `__FUNCTION__` and `func_get_args()`:

```php
namespace App\Services;

use App\Models\Category;
use KaziOmar\AtomicCache\Traits\HasAtomicCache;

class CategoryService
{
    use HasAtomicCache;

    // Optional: Defaults to 'categories' (derived from class name)
    protected string $cacheResource = 'categories';

    /**
     * Cache list queries by method name and arguments.
     */
    public function getAllCategories()
    {
        $callback = fn () => Category::withCount('products')
                                    ->where('is_active', true)
                                    ->get();

        return $this->cache(__FUNCTION__, func_get_args(), $callback, ttl: 3600);
    }

    /**
     * Cache single records with dynamic arguments.
     */
    public function getCategoryBySlug(string $slug): ?Category
    {
        $callback = fn () => Category::with(['subcategories' => fn ($q) => $q->active()])
                                     ->where('slug', $slug)
                                     ->firstOrFail();

        return $this->cache(__FUNCTION__, func_get_args(), $callback, ttl: 3600);
    }

    /**
     * Cache complex multi-parameter analytical or aggregation queries.
     */
    public function getCategoryMetrics(int $categoryId, string $metricType = 'daily')
    {
        return $this->cache(__FUNCTION__, func_get_args(), function () use ($categoryId, $metricType) {
            // Expensive aggregation or external service calls...
            return [
                'category_id' => $categoryId,
                'metric' => $metricType,
                'calculated_at' => now()->toDateTimeString(),
            ];
        }, ttl: 1800);
    }
}
```

#### What do `__FUNCTION__` and `func_get_args()` do?
* **`__FUNCTION__`**: A native PHP magic constant that automatically returns the string name of the current enclosing function or method (e.g. `'getAllCategories'` or `'getCategoryBySlug'`).
* **`func_get_args()`**: A native PHP function that returns an indexed array of all runtime arguments passed into the method (e.g. `[]` for `getAllCategories()`, or `['electronics']` for `getCategoryBySlug('electronics')`).
* **Deterministic Key Composition**: Atomic Cache combines the resource tag, method name, and a serialized MD5 hash of safe arguments to form a collision-proof cache key:
  * Without arguments: `{resource}:{method}` &rarr; `categories:getAllCategories`
  * With arguments: `{resource}:{method}:{argsHash}` &rarr; `categories:getCategoryBySlug:9df2a4...`
* **Safe Argument Normalization**: If your method receives Eloquent models, closures, or complex objects, Atomic Cache normalizes them into lightweight identifiers (`App\Models\Category:12`) so PHP's `serialize()` never throws un-serializable errors.

#### Method Signature & Additional Options:
```php
public function cache(
    string $method,
    array $args = [],
    ?Closure $callback = null,
    ?int $ttl = null,
    bool|array|null $perUserOrTags = null,
    bool $lock = false
): mixed
```
* **`ttl`**: Cache lifetime in seconds (defaults to class `$cacheTTL` property or `3600`).
* **`perUserOrTags`**: 
  * Pass `true` to isolate the cache per authenticated user (`guest` for unauthenticated sessions).
  * Pass an array of tags (e.g. `perUserOrTags: ['categories', 'products']`) if the query depends on multiple database tables so changes in either table will invalidate this cache.
* **`lock: true`**: Activates atomic distributed locking to prevent stampede herd conditions during cache warmup.

---

### 4.2 Pagination & Filter Isolation (`rememberCollection`)

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
        return $this->rememberCollection('active', fn () => Product::where('status', 'active')->paginate(15));
    }
}
```

---

### 4.3 Single Item Caching (`rememberItem` / `cacheItem`)

```php
public function findProduct(int $id)
{
    // Caches under key "products:{id}", tagged with "products:{id}" and "products":
    return $this->rememberItem($id, fn () => Product::findOrFail($id));
}
```

---

### 4.4 Service-Level Cache Invalidation Helpers

When performing write operations (create, update, delete) inside your service, you can invalidate the relevant cached scopes directly from within the service:

```php
public function updateCategory(int $id, array $data): Category
{
    $category = Category::findOrFail($id);
    $category->update($data);

    // 1. Invalidate a single item key ("categories:{id}"):
    $this->clearItemCache($id);

    // 2. Invalidate all collection/list queries for this resource:
    $this->clearCollectionCache();

    // 3. Or wipe all caches (items + collections) under this resource tag:
    $this->clearAllCache();

    return $category;
}
```

---

### 4.5 Query Parameter Sanitization & Whitelisting

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

### 4.6 DoS & Cache Poisoning Limits

To protect against memory exhaustion attacks, Atomic Cache enforces strict limits on request queries:
* **Max Parameters (Default: 20):** Queries exceeding 20 parameters cleanly bypass the cache and run against the database.
* **Max Size (Default: 2048 bytes):** Serialized query strings exceeding 2KB cleanly bypass caching.

---

### 4.7 Service Configuration Reference

Configure caching behavior per service by declaring optional protected properties:

```php
class ProductService
{
    use HasAtomicCache;

    // Custom base tag & key prefix (defaults to snake_plural of class name without Service/Repository):
    protected string $cacheResource = 'products';

    // Default TTL in seconds for all cache calls in this class (defaults to 3600):
    protected int $cacheTTL = 7200;

    // Automatically scope all caches in this class per authenticated user (defaults to false):
    protected bool $cachePerUser = false;

    // Whitelist allowed query parameters for request pagination & filters (DDoS prevention):
    protected array $allowedQueryParams = ['page', 'sort', 'filter'];

    // Master kill-switch to bypass caching during tests or debugging (defaults to true):
    protected bool $cachingEnabled = true;
}
```

---

### 4.8 Critical Rules & What Breaks Service Caching

* **Do NOT use `remember()` for paginated data:**
  * If you call `$this->remember('active_products', 3600, fn() => Product::paginate(15))` instead of `rememberCollection()`, it will **not** inspect `request()->query()`. Page 1 results will be cached and returned for Page 2, Page 3, and all other pages. Always use `rememberCollection()`.
* **Resource name guessing mismatches:**
  * If your service class is named `CatalogManager`, the trait guesses the resource tag as `'catalog_managers'`. If your database table is `products`, `Product` model events will clear `'products'`, leaving `'catalog_managers'` stale.
  * *Solution:* Always explicitly declare `protected string $cacheResource = 'products';` when the class name does not match the table name.
* **Un-serializable objects in `$extraArgs`:**
  * Passing closures with un-serializable references into `$extraArgs` will prevent deterministic cache key generation. Pass scalar values (integers, strings, arrays) instead.
