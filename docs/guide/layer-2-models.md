# Layer 2: Eloquent Model Lifecycle (`AutoClearsCache`)

Add `AutoClearsCache` to any Eloquent model to automate cache invalidations.

---

### 2.1 Automatic Invalidation on `DB::afterCommit`

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

Similar in spirit to Laravel's native `$touches` (operating on a single-level `BelongsTo` relation), you can declare parent relationships to automatically invalidate parent-scoped cache tags whenever a child record changes:

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
* Flushes child item tag: `orders:{id}`
* Flushes child collection tag: `orders`
* Flushes parent scoped tag: `stores:5:orders`
* Flushes parent umbrella tag: `stores:5`
* **Dirty Foreign Key Handling:** If an order is moved from Store 1 to Store 2 (`$order->store_id = 2`), Atomic Cache invalidates tags for **both** Store 1 and Store 2.

::: info Parent Umbrella Tag Scope
By design, `$cacheParents` flushes both the scoped collection tag (`stores:5:orders`) and the parent umbrella tag (`stores:5`). If your application caches the parent model's own record under `stores:5`, child writes will also refresh the parent's general cache.
:::

---

### 2.3 Model-Level Manual Cache Flushing (`flushCache` & `clearCache`)

`AutoClearsCache` provides two convenient helper methods directly on the model:

```php
// 1. Instance helper: manually clear this record and its parent tags:
$product = Product::find(42);
$product->clearCache(); // Expressive alias for clearModelCacheOnCommit()

// 2. Static helper: flush all collection-level caches for this model's table:
Product::where('category_id', 5)->update(['discount' => 10]);
Product::flushCache(); // Wipes all "products" table collection caches in one line!
```

::: warning Scope & Transaction Safety
`Product::flushCache()` invalidates the model's **table collection tag** (`products`). It does **not** invalidate individual item tags (`products:42`) or parent-scoped relation tags (`stores:5:orders`). If your model uses parent-scoped caching (`$cacheParents`), invalidate those tags explicitly via `AtomicCache::clearTags(['stores:5:orders', 'stores:5'])`.

Unlike model lifecycle events which wait for `afterCommit`, `Product::flushCache()` executes immediately. If calling it inside an active `DB::transaction()`, wrap it in `DB::afterCommit(fn() => Product::flushCache())` to prevent concurrent readers from caching pre-committed data.
:::

---

### 2.4 High-Frequency Update Columns (`$cacheIgnoreColumns`)

When updating counters or analytics (e.g. `view_count`, `last_login_at`, `login_count`), invalidating the entire table collection cache (`products`) or parent cache (`stores:1:products`) on every single page view causes severe cache churn.

Declare `$cacheIgnoreColumns` on your model to protect collections:

```php
class Product extends Model
{
    use AutoClearsCache;

    protected array $cacheParents = ['store'];

    // Only single-item cache ('products:42') is cleared when these change.
    // Heavy table collections ('products') and parent tags ('stores:1:products') remain cached:
    protected array $cacheIgnoreColumns = ['view_count', 'meta'];
}
```

::: info Automatic Timestamp Exclusion
Laravel automatically dirties the `updated_at` column on save. Atomic Cache automatically ignores `updated_at` when evaluating `$cacheIgnoreColumns`. If you call `$model->touch()`, Atomic Cache verifies that no declared ignored column changed and correctly flushes the collection cache.

`$cacheIgnoreColumns` only applies to `updated` events. Model `created`, `deleted`, and `restored` events always invalidate collection tags regardless of this setting.
:::

::: warning Stale Display & Sorting Warning
Columns added to `$cacheIgnoreColumns` will remain stale in cached query results and listings until their TTL expires.

For example, if you ignore `view_count`, any cached query that selects, filters (`where`), or orders by (`orderBy('view_count')`) will display the old view count or stale ordering until refreshed.

**Rule of Thumb:** Only ignore columns that are not displayed in cached queries or critical sorting operations (e.g. internal telemetry, session metadata, or live counters rendered via dedicated un-cached endpoints).
:::

---

### 2.5 Strict Mode for Parent Relations (`strict_mode`)

Catch typos and invalid relationship declarations early during local development:

```php
// config/atomic-cache.php
'strict_mode' => env('ATOMIC_CACHE_STRICT_MODE', null), // null = active in local environment (app()->isLocal() === true)
```

When strict mode is enabled (default in local development, or by setting `ATOMIC_CACHE_STRICT_MODE=true` in `phpunit.xml`), invalid declarations throw an immediate `\InvalidArgumentException` with clear guidance:
* Typo in method name (`$cacheParents = ['strore']`): throws missing method error.
* Class name passed instead of relation method (`$cacheParents = [Store::class]`): detects class name and asks: *"Did you pass a class name instead of the BelongsTo relation method name?"*.
* Invalid type passed (`$cacheParents = [123]`): throws invalid type error.
* Method requiring parameters (`public function store($type)`): throws required parameter error.
* Non-`BelongsTo` relations (`hasMany`, `morphTo`): throws relation type error.

In production environments, strict mode safely logs a warning and gracefully continues without interrupting customer transactions.

---

### 2.6 Transaction-Safe Bulk Invalidation (`AtomicCache::invalidating`)

For bulk updates, mass deletes, or service workflows using direct DB queries, wrap execution with `AtomicCache::invalidating()`:

```php
use KaziOmar\AtomicCache\Facades\AtomicCache;

// Outside transaction: flushes immediately
// Inside transaction: automatically defers invalidation to DB::afterCommit!
AtomicCache::invalidating([Order::class, Product::class], function () {
    DB::table('orders')->where('status', 'draft')->delete();
    DB::table('products')->where('stock', 0)->update(['status' => 'out_of_stock']);
});
```

Uses a `try/finally` guarantee: outside a transaction, invalidation is guaranteed in a `finally` block even if the closure throws an exception, preventing stale reads after partial database mutations. Inside an active database transaction, invalidation is safely registered via `DB::afterCommit()`. If the transaction rolls back, cache is untouched.

---

### 2.7 Critical Rules & What Breaks Model Auto-Invalidation

* **Mass queries bypass model events:** Direct query builder mutations (`Product::where(...)->update(...)`, `delete()`, or `DB::table('products')->update(...)`) **do not fire Eloquent events**. AutoClearsCache cannot detect them. 
  * *Solution:* Run `Product::flushCache();` right after your bulk query.
* **Quiet operations bypass events:** Calling `$model->saveQuietly()`, `updateQuietly()`, `deleteQuietly()`, or wrapping code in `Model::withoutEvents()` intentionally suppresses model events.
  * *Solution:* Manually call `$model->clearCache()` if you need invalidation after quiet saves.
* **Pivot table changes:** Many-to-many `attach()`, `detach()`, or `sync()` operations on relationships do not fire parent model events.
  * *Solution:* Call `AtomicCache::clearTags([Product::class, Category::class])` explicitly after syncing.
* **`$cacheParents` only supports `BelongsTo`:** Declaring `hasMany`, `hasOne`, `belongsToMany`, or `morphTo` in `$cacheParents` will throw an `InvalidArgumentException` in strict mode (local/testing) or log a warning in production.
* **Custom owner keys are skipped:** If a relationship uses a custom non-primary owner key (e.g. `belongsTo(Store::class, 'store_slug', 'slug')`), it is skipped because the child model holds a string slug rather than the parent's primary ID, which would create mismatched umbrella tags (`stores:nike` vs `stores:5`).
* **Grandparent relations do not auto-cascade (`$touches` does not work):** Multi-level hierarchies (e.g. `OrderItem -> Order -> Store`) do not automatically cascade from item to store. **Crucially, Laravel's native `protected $touches = ['order']` property executes a raw database update (`rawUpdate`) and does NOT fire Eloquent model events.** Thus, declaring `$touches` will not trigger `AutoClearsCache` on the parent!
  * *Solution 1 (Recommended - Explicit Method):* Define `getRelatedCacheTags()` on the child model:
    ```php
    public function getRelatedCacheTags(): array
    {
        return [
            "stores:{$this->order->store_id}:orders",
            "stores:{$this->order->store_id}",
        ];
    }
    ```
  * *Solution 2 (Manual Touch):* Call `$orderItem->order->touch()` directly on the model instance (which calls `save()` and fires Eloquent's `updated` event).
* **Runtime property mutation is ignored:** Because configured parent relations are cached in-memory per class for performance, dynamically modifying `$model->cacheParents = [...]` on an active model instance at runtime will have no effect.
* **Octane / Long-Running Workers:** If you dynamically manipulate `$cacheParents` during testing or worker loops, clear the in-memory cache via `Model::flushConfiguredParentRelationsCache()`.
* **Rolled-back transactions do not clear cache:** If changes occur inside a `DB::transaction()` that throws an exception and rolls back, `afterCommit()` will not fire. The cache remains untouched.
