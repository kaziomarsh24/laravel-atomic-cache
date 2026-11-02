# Layer 4: Service & Repository Layer (`HasAtomicCache`)

Standardize caching in your service or repository classes by adding the `HasAtomicCache` trait.

---

### 4.1 Automatic Pagination & Filter Isolation (`rememberCollection`)

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
* **Max Parameters (Default: 20):** Queries exceeding 20 parameters cleanly bypass the cache and run against the database.
* **Max Size (Default: 2048 bytes):** Serialized query strings exceeding 2KB cleanly bypass caching.

---

### 4.5 Critical Rules & What Breaks Service Caching

* **Do NOT use `remember()` for paginated data:**
  * If you call `$this->remember('active_products', 3600, fn() => Product::paginate(15))` instead of `rememberCollection()`, it will **not** inspect `request()->query()`. Page 1 results will be cached and returned for Page 2, Page 3, and all other pages. Always use `rememberCollection()`.
* **Resource name guessing mismatches:**
  * If your service class is named `CatalogManager`, the trait guesses the resource tag as `'catalog_managers'`. If your database table is `products`, `Product` model events will clear `'products'`, leaving `'catalog_managers'` stale.
  * *Solution:* Always explicitly declare `protected string $cacheResource = 'products';` when the class name does not match the table name.
* **Un-serializable objects in `$extraArgs`:**
  * Passing closures with un-serializable references into `$extraArgs` will prevent deterministic cache key generation. Pass scalar values (integers, strings, arrays) instead.
