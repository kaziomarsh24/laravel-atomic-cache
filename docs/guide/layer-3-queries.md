# Layer 3: Query Builder Macros (`->atomicCache()`)

Atomic Cache registers expressive macros directly onto Laravel's Eloquent and Database Query Builders, eliminating manual cache key construction.

---

### 3.1 Zero-Key Query & First Record Caching

Chain `->atomicCache()` directly off any query:

```php
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

// 1. Auto-generates sha256 hash from SQL + bindings + eager loads
// Automatically tagged with 'products' and Product::class for auto-invalidation:
$featured = Product::where('status', 'active')
    ->where('featured', 1)
    ->atomicCache(3600);

// 2. Single record caching:
$product = Product::where('slug', $slug)->atomicCacheFirst(3600);

// 3. Custom key mode:
$bestSellers = Product::where('sales', '>', 500)->atomicCache('bestsellers', 7200);

// 4. Scoped Tagging & Tenant Isolation (Omits broad 'orders' table tag):
$storeOrders = Order::where('store_id', 5)->atomicCache(scope: 'stores:5:orders');
$storeFirst = Order::where('store_id', 5)->latest()->atomicCacheFirst(scope: 'stores:5:orders');

// 5. Works on DB facade and relationships:
$stats = DB::table('orders')->where('status', 'paid')->atomicCache(1800);
$userOrders = $user->orders()->latest()->atomicCache(3600);
```

---

### 3.2 Critical Rules & What Breaks Query Macros

* **Do NOT chain `->paginate()` after `->atomicCache()`:**
  * `atomicCache()` executes `->get()` immediately and returns an Eloquent/Support `Collection`. Chaining `->paginate()` afterward throws a fatal error: `Method paginate does not exist on Collection`.
  * *Solution:* For pagination, use the Service Layer's `rememberCollection()` method (see Layer 4).
* **Mass updates will not clear query macro caches:**
  * If you cache a query via `Product::where('active', 1)->atomicCache()`, running `Product::where('active', 1)->update(['price' => 20])` will not clear the cache because no Eloquent event fires.
  * *Solution:* Call `Product::flushCache();` after the update.
* **`scope:` queries and mass updates:**
  * `Order::flushCache()` or `invalidating([Order::class], ...)` only invalidates the base `orders` collection tag. Scoped queries cached via `scope: 'stores:5:orders'` will **not** be cleared by broad table flushes.
  * *Solution:* Invalidate the scoped tag explicitly during mass updates:
    ```php
    AtomicCache::invalidating(['stores:5:orders', Order::class], fn() => ...);
    // or
    AtomicCache::clearTags(['stores:5:orders']);
    ```
* **`scope:` queries omit automatic joins & require `$cacheParents`:**
  * When `scope:` is passed, automatic join-table tagging is omitted in favor of your explicit scope tag. Furthermore, model lifecycle events (`Order::create()`) only invalidate the scoped cache if the model defines a matching `$cacheParents` relation (e.g. `protected array $cacheParents = ['store'];`).
* **Eager loading does not auto-tag related tables:**
  * Eager-loaded relationships (`Product::with('category')->atomicCache()`) are included in the query hash fingerprint, but related tables (`categories`) are **not** auto-tagged. If a category is updated, the product query cache will not automatically invalidate.
  * *Solution:* Pass related table tags explicitly: `->atomicCache(3600, tags: ['categories'])`.
* **Raw SQL subqueries are not auto-tagged:**
  * While standard `join()` and `leftJoin()` methods are inspected and auto-tagged, raw SQL joins or subqueries (`whereRaw('exists (select 1 from categories ...)')`) cannot be parsed for table names.
  * *Solution:* Pass additional table tags explicitly: `->atomicCache(3600, tags: ['categories'])`.
* **Parameter order vigilance:**
  * The signature is `atomicCache($keyOrTtl = null, $ttl = null, array $tags = [])`. Passing `$query->atomicCache(3600)` treats `3600` as TTL. If you want a custom key, pass the key first: `$query->atomicCache('my_key', 3600)`.
