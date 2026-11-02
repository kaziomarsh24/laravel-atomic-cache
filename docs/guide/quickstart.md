# 10-Second Quickstart

You can use Atomic Cache in two ways: via the **Core Facade** for explicit, fine-grained control, or via **Model & Query Macros** for declarative automation.

---

### Approach A: Explicit Core Caching (Recommended)

Use the `AtomicCache` facade directly in your controllers or services. This provides distributed stampede locks and virtual tagging across any cache driver:

```php
use App\Models\Product;
use KaziOmar\AtomicCache\Facades\AtomicCache;

// Tagged, stampede-proof caching across any store:
$catalog = AtomicCache::tags(['products'])->rememberWithLock('active_products', 3600, function () {
    return Product::where('status', 'active')->get();
});
```

---

### Approach B: Declarative Model & Query Automation

For zero-boilerplate caching, couple your Eloquent models and queries directly:

#### 1. Add Trait to your Model:
Add `AutoClearsCache` to any Eloquent model:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

class Product extends Model
{
    use AutoClearsCache;
}
```

#### 2. Chain Macro in your Queries:
Chain `->atomicCache()` directly off your query builder:

```php
// Automatically hashes the SQL query and tags it with 'products':
$products = Product::where('status', 'active')->latest()->atomicCache(3600);

// Single record caching:
$product = Product::where('slug', $slug)->atomicCacheFirst(3600);
```

#### 3. Audit Model Invalidation Health:
Run the built-in diagnostic doctor to verify that relationship tags and model invalidation paths are correctly configured:

```bash
php artisan atomic-cache:check
```
