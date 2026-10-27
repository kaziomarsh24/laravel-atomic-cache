<?php

namespace KaziOmar\AtomicCache\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use KaziOmar\AtomicCache\Facades\AtomicCache;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;

/* -------------------------------------------------------------------------
| Test Models with Declarative Parent Invalidation
| ------------------------------------------------------------------------- */

class TestStoreModel extends Model
{
    use AutoClearsCache;

    protected $table = 'test_stores';
    protected $guarded = [];
}

class TestOrderModel extends Model
{
    use AutoClearsCache;
    use SoftDeletes;

    protected $table = 'test_orders';
    protected $guarded = [];

    // Convention 1: Laravel $touches style
    protected array $cacheTouches = ['store'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(TestStoreModel::class, 'store_id');
    }
}

class TestProductModel extends Model
{
    use AutoClearsCache;

    protected $table = 'test_products';
    protected $guarded = [];

    // Convention 2: Expressive clean English
    protected array $clearsRelations = ['store'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(TestStoreModel::class, 'store_id');
    }
}

class TestItemModel extends Model
{
    use AutoClearsCache;

    protected $table = 'test_items';
    protected $guarded = [];

    // Convention 3: Standard package convention
    protected array $cacheParents = ['store'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(TestStoreModel::class, 'store_id');
    }
}

class TestCommentModel extends Model
{
    use AutoClearsCache;

    protected $table = 'test_comments';
    protected $guarded = [];

    // Edge Case: Polymorphic MorphTo relation should be safely ignored
    protected array $cacheTouches = ['commentable'];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class TestFaultyRelationModel extends Model
{
    use AutoClearsCache;

    protected $table = 'test_faulty_models';
    protected $guarded = [];

    // Edge Case: Method requires parameters, must not cause ArgumentCountError
    protected array $cacheTouches = ['invalidMethodWithParams', 'nonExistentMethod'];

    public function invalidMethodWithParams($requiredParam): BelongsTo
    {
        return $this->belongsTo(TestStoreModel::class, 'store_id');
    }
}

class TestCombinedTagsModel extends Model
{
    use AutoClearsCache;

    protected $table = 'test_orders';
    protected $guarded = [];

    // Combining $cacheTouches with manual getRelatedCacheTags()
    protected array $cacheTouches = ['store'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(TestStoreModel::class, 'store_id');
    }

    public function getRelatedCacheTags(): array
    {
        return ['analytics:global_dashboard'];
    }
}

class TestUninitializedPropertyModel extends Model
{
    use AutoClearsCache;
    protected $table = 'test_orders';
    protected array $cacheParents; // Uninitialized typed property
}

class TestCustomOwnerKeyModel extends Model
{
    use AutoClearsCache;
    protected $table = 'test_orders';
    protected array $cacheParents = ['storeBySlug'];

    public function storeBySlug(): BelongsTo
    {
        return $this->belongsTo(TestStoreModel::class, 'store_slug', 'slug');
    }
}

class TestCommentBelongsToModel extends Model
{
    use AutoClearsCache;
    protected $table = 'test_orders';
    public static bool $commentMethodWasCalled = false;

    public function fakeRelation()
    {
        // This belongsTo the order processing team
        self::$commentMethodWasCalled = true;
        return 'not a relation';
    }
}

enum TestStoreEnum: int
{
    case Outlet = 77;
    case Flagship = 88;
}

class TestMultiParentModel extends Model
{
    use AutoClearsCache;

    protected $table = 'test_orders';
    protected $guarded = [];
    protected array $cacheParents = ['store', 'product'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(TestStoreModel::class, 'store_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(TestProductModel::class, 'product_id');
    }
}

class TestPrivateCacheParentsModel extends Model
{
    use AutoClearsCache;
    protected $table = 'test_orders';
    private array $cacheParents = ['store'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(TestStoreModel::class, 'store_id');
    }
}

/* -------------------------------------------------------------------------
| Unit Test Suite for Declarative Parent Cache Invalidation
| ------------------------------------------------------------------------- */


class TestStrictTypoModel extends Model
{
    use AutoClearsCache;
    protected $table = 'test_strict_typo';
    protected array $cacheParents = ['nonExistentRelation'];
}

class TestStrictParamModel extends Model
{
    use AutoClearsCache;
    protected $table = 'test_strict_param';
    protected array $cacheParents = ['relationWithParam'];

    public function relationWithParam($requiredArg): BelongsTo
    {
        return $this->belongsTo(TestStoreModel::class, 'store_id');
    }
}

class TestStrictMorphModel extends Model
{
    use AutoClearsCache;
    protected $table = 'test_strict_morph';
    protected array $cacheParents = ['morphRelation'];

    public function morphRelation(): MorphTo
    {
        return $this->morphTo();
    }
}


class TestProductWithIgnoredColumnsModel extends Model
{
    use AutoClearsCache;
    protected $table = 'test_products_ignored';
    protected $guarded = [];
    protected array $cacheParents = ['store'];
    protected array $cacheIgnoreColumns = ['view_count', 'meta'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(TestStoreModel::class, 'store_id');
    }
}
class ParentCacheTouchesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('test_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable();
            $table->foreignId('product_id')->nullable();
            $table->string('status')->default('pending');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('test_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('test_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('test_comments', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('commentable');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('test_faulty_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable();
            $table->timestamps();
        });

        Schema::create('test_products_ignored', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable();
            $table->string('title')->default('Item');
            $table->integer('view_count')->default(0);
            $table->string('meta')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        TestOrderModel::flushConfiguredParentRelationsCache();
        Schema::dropIfExists('test_products_ignored');
        Schema::dropIfExists('test_faulty_models');
        Schema::dropIfExists('test_comments');
        Schema::dropIfExists('test_items');
        Schema::dropIfExists('test_products');
        Schema::dropIfExists('test_orders');
        Schema::dropIfExists('test_stores');
        parent::tearDown();
    }

    /**
     * Test 1: Happy path - creating and updating a child model clears parent scoped and umbrella tags.
     */
    public function test_cache_touches_clears_parent_tags_on_create_and_update(): void
    {
        $store = TestStoreModel::create(['name' => 'Main Outlet']);

        // Cache store orders and general store umbrella data
        AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->put('order_list', ['order1'], 3600);
        AtomicCache::tags(["test_stores:{$store->id}"])->put('store_profile', 'details', 3600);

        $this->assertEquals(['order1'], AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->get('order_list'));
        $this->assertEquals('details', AtomicCache::tags(["test_stores:{$store->id}"])->get('store_profile'));

        // Creating an order in store
        $order = TestOrderModel::create(['store_id' => $store->id, 'status' => 'pending']);

        // Assert parent cache tags were flushed
        $this->assertNull(AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->get('order_list'));
        $this->assertNull(AtomicCache::tags(["test_stores:{$store->id}"])->get('store_profile'));

        // Re-cache data
        AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->put('order_list', ['order1'], 3600);

        // Updating the order
        $order->update(['status' => 'shipped']);

        $this->assertNull(AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->get('order_list'));
    }

    /**
     * Test 2: Edge Case 1 - Changing foreign key (Store 1 -> Store 2) invalidates BOTH stores' caches.
     */
    public function test_changing_foreign_key_invalidates_both_old_and_new_parent_caches(): void
    {
        $store1 = TestStoreModel::create(['name' => 'Store One']);
        $store2 = TestStoreModel::create(['name' => 'Store Two']);

        $order = TestOrderModel::create(['store_id' => $store1->id, 'status' => 'pending']);

        // Cache lists for both stores
        AtomicCache::tags(["test_stores:{$store1->id}:test_orders"])->put('s1_orders', 'store_1_data', 3600);
        AtomicCache::tags(["test_stores:{$store2->id}:test_orders"])->put('s2_orders', 'store_2_data', 3600);

        $this->assertEquals('store_1_data', AtomicCache::tags(["test_stores:{$store1->id}:test_orders"])->get('s1_orders'));
        $this->assertEquals('store_2_data', AtomicCache::tags(["test_stores:{$store2->id}:test_orders"])->get('s2_orders'));

        // Move order from Store 1 to Store 2
        $order->update(['store_id' => $store2->id]);

        // Both old parent (Store 1) and new parent (Store 2) MUST be cleared to avoid ghost data
        $this->assertNull(AtomicCache::tags(["test_stores:{$store1->id}:test_orders"])->get('s1_orders'));
        $this->assertNull(AtomicCache::tags(["test_stores:{$store2->id}:test_orders"])->get('s2_orders'));
    }

    /**
     * Test 2b: Changing foreign key inside DB::transaction invalidates BOTH stores after commit.
     */
    public function test_changing_foreign_key_inside_transaction_invalidates_both_old_and_new_parent_caches(): void
    {
        $store1 = TestStoreModel::create(['name' => 'Store One']);
        $store2 = TestStoreModel::create(['name' => 'Store Two']);

        $order = TestOrderModel::create(['store_id' => $store1->id, 'status' => 'pending']);

        // Cache lists for both stores
        AtomicCache::tags(["test_stores:{$store1->id}:test_orders"])->put('s1_orders', 'store_1_data', 3600);
        AtomicCache::tags(["test_stores:{$store2->id}:test_orders"])->put('s2_orders', 'store_2_data', 3600);

        $this->assertEquals('store_1_data', AtomicCache::tags(["test_stores:{$store1->id}:test_orders"])->get('s1_orders'));
        $this->assertEquals('store_2_data', AtomicCache::tags(["test_stores:{$store2->id}:test_orders"])->get('s2_orders'));

        // Move order from Store 1 to Store 2 inside DB::transaction
        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $store2) {
            $order->update(['store_id' => $store2->id]);
        });

        // Both old parent (Store 1) and new parent (Store 2) MUST be cleared after transaction commits
        $this->assertNull(AtomicCache::tags(["test_stores:{$store1->id}:test_orders"])->get('s1_orders'), 'Old parent cache was NOT cleared after transaction commit!');
        $this->assertNull(AtomicCache::tags(["test_stores:{$store2->id}:test_orders"])->get('s2_orders'));
    }

    /**
     * Test 3: Edge Case 2 - Null foreign key does not generate malformed tags like 'test_stores::test_orders'.
     */
    public function test_null_foreign_key_does_not_generate_malformed_tags(): void
    {
        $order = new TestOrderModel(['store_id' => null, 'status' => 'draft']);
        $tags = $order->resolveCacheTagsToClear();

        // Should contain child table tags, but never an empty parent tag
        $this->assertContains('test_orders', $tags);
        $this->assertNotContains('test_stores::test_orders', $tags);
        $this->assertNotContains('test_stores:', $tags);
    }

    /**
     * Test 4: All three property naming conventions ($cacheTouches, $clearsRelations, $cacheParents) work identically.
     */
    public function test_all_three_naming_conventions_work(): void
    {
        $store = TestStoreModel::create(['name' => 'Convention Store']);

        // Convention 2: $clearsRelations
        AtomicCache::tags(["test_stores:{$store->id}:test_products"])->put('prod_key', 'prod_val', 3600);
        TestProductModel::create(['store_id' => $store->id, 'name' => 'Laptop']);
        $this->assertNull(AtomicCache::tags(["test_stores:{$store->id}:test_products"])->get('prod_key'));

        // Convention 3: $cacheParents
        AtomicCache::tags(["test_stores:{$store->id}:test_items"])->put('item_key', 'item_val', 3600);
        TestItemModel::create(['store_id' => $store->id, 'name' => 'Mouse']);
        $this->assertNull(AtomicCache::tags(["test_stores:{$store->id}:test_items"])->get('item_key'));
    }

    /**
     * Test 5: Edge Case 8 - Polymorphic MorphTo relation is safely skipped without throwing errors.
     */
    public function test_polymorphic_relation_is_safely_ignored_without_errors(): void
    {
        $comment = TestCommentModel::create([
            'commentable_type' => TestStoreModel::class,
            'commentable_id' => 1,
            'body' => 'Great store!',
        ]);

        $tags = $comment->resolveCacheTagsToClear();
        $this->assertContains('test_comments', $tags);
        $this->assertContains("test_comments:{$comment->id}", $tags);
    }

    /**
     * Test 6: Edge Case 9 - Method requiring arguments or non-existent method is safely skipped without crash.
     */
    public function test_method_with_required_parameters_does_not_crash(): void
    {
        $faulty = TestFaultyRelationModel::create(['store_id' => 1]);
        $tags = $faulty->resolveCacheTagsToClear();

        $this->assertContains('test_faulty_models', $tags);
        $this->assertContains("test_faulty_models:{$faulty->id}", $tags);
    }

    /**
     * Test 7: Edge Case 4 - SoftDeletes delete and restore correctly clear parent caches.
     */
    public function test_soft_deletes_and_restore_clears_parent_tags(): void
    {
        $store = TestStoreModel::create(['name' => 'Soft Delete Store']);
        $order = TestOrderModel::create(['store_id' => $store->id, 'status' => 'pending']);

        // Cache data
        AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->put('soft_key', 'active_data', 3600);
        $this->assertEquals('active_data', AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->get('soft_key'));

        // Soft delete order
        $order->delete();
        $this->assertNull(AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->get('soft_key'));

        // Re-cache data
        AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->put('soft_key', 'active_data', 3600);

        // Restore order
        $order->restore();
        $this->assertNull(AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->get('soft_key'));
    }

    /**
     * Test 8: Combining $cacheTouches with manual getRelatedCacheTags() preserves backward compatibility.
     */
    public function test_combining_cache_touches_with_custom_related_tags(): void
    {
        $combined = new TestCombinedTagsModel(['store_id' => 42]);
        $tags = $combined->resolveCacheTagsToClear();

        $this->assertContains('test_stores:42:test_orders', $tags);
        $this->assertContains('test_stores:42', $tags);
        $this->assertContains('analytics:global_dashboard', $tags);
    }

    /**
     * Test 9: CheckAtomicCacheCommand correctly detects declared cacheTouches tags in details.
     */
    public function test_command_extracts_declared_cache_touches_tags(): void
    {
        $instance = new TestOrderModel();
        $command = new \KaziOmar\AtomicCache\Commands\CheckAtomicCacheCommand();
        $refMethod = new \ReflectionMethod($command, 'extractRelatedTags');
        $tags = $refMethod->invoke($command, $instance);

        $this->assertContains('test_stores:{id}:test_orders', $tags);
        $this->assertContains('test_stores:{id}', $tags);
    }

    /**
     * Test 10: CheckAtomicCacheCommand inspectModelBelongsToAdvisories generates proactive suggestions for unlinked models.
     */
    public function test_command_inspect_advisories_suggests_fix_for_unlinked_belongs_to(): void
    {
        // Model without $cacheTouches
        $unlinkedModel = new class extends Model {
            use AutoClearsCache;
            protected $table = 'test_orders';
            public function store(): BelongsTo
            {
                return $this->belongsTo(TestStoreModel::class, 'store_id');
            }
        };

        $command = new \KaziOmar\AtomicCache\Commands\CheckAtomicCacheCommand();
        $refMethod = new \ReflectionMethod($command, 'inspectModelBelongsToAdvisories');
        $advisories = $refMethod->invoke($command, get_class($unlinkedModel), $unlinkedModel, []);

        $this->assertNotEmpty($advisories);
        $this->assertEquals('store', $advisories[0]['relation']);
        $this->assertEquals("protected array \$cacheParents = ['store'];", $advisories[0]['suggested_code']);
        $this->assertStringContainsString('test_stores:', $advisories[0]['suggested_tag']);
    }

    /**
     * Test 11: A single string declaration (e.g. protected $cacheParents = 'store') works just like an array.
     */
    public function test_string_single_cache_parent_declaration_works(): void
    {
        $singleStringModel = new class extends Model {
            use AutoClearsCache;
            protected $table = 'test_orders';
            protected $cacheParents = 'store';
            public function store(): BelongsTo
            {
                return $this->belongsTo(TestStoreModel::class, 'store_id');
            }
        };

        $singleStringModel->store_id = 99;
        $tags = $singleStringModel->resolveCacheTagsToClear();

        $this->assertContains('test_stores:99:test_orders', $tags);
        $this->assertContains('test_stores:99', $tags);
    }

    /**
     * Test 12: Arbitrary model methods (without belongsTo) are guarded and never called by the advisory linter.
     */
    public function test_non_relation_method_is_not_called_by_advisories_linter(): void
    {
        $dangerousModel = new class extends Model {
            use AutoClearsCache;
            protected $table = 'test_orders';
            public static bool $wasCalled = false;

            public function dangerousOperation(): void
            {
                self::$wasCalled = true;
            }

            public function calculateInvoice()
            {
                self::$wasCalled = true;
                return 'calculated';
            }
        };

        $command = new \KaziOmar\AtomicCache\Commands\CheckAtomicCacheCommand();
        $refMethod = new \ReflectionMethod($command, 'inspectModelBelongsToAdvisories');
        $advisories = $refMethod->invoke($command, get_class($dangerousModel), $dangerousModel, []);

        $this->assertEmpty($advisories);
        $this->assertFalse($dangerousModel::$wasCalled, 'Dangerous method should never have been invoked by linter!');
    }

    /**
     * Test 13: Uninitialized typed property (protected array $cacheParents;) does not throw fatal error.
     */
    public function test_uninitialized_typed_property_does_not_throw_error(): void
    {
        $model = new TestUninitializedPropertyModel();

        // 1. Model trait resolution must not throw "Typed property must not be accessed before initialization"
        $tags = $model->resolveCacheTagsToClear();
        $this->assertContains('test_orders', $tags);

        // 2. Command reflection extraction must not throw error
        $command = new \KaziOmar\AtomicCache\Commands\CheckAtomicCacheCommand();
        $refMethod = new \ReflectionMethod($command, 'getDeclaredParentRelations');
        $result = $refMethod->invoke($command, $model);
        $this->assertEquals([], $result);
    }

    /**
     * Test 14: Custom owner keys (slug/uuid differing from parent primary key) are safely ignored.
     */
    public function test_custom_owner_key_belongs_to_is_safely_ignored(): void
    {
        $model = new TestCustomOwnerKeyModel();
        $model->store_slug = 'nike-store';

        // Custom owner key does not match parent primary key, so mismatched umbrella tags are avoided
        $tags = $model->resolveCacheTagsToClear();
        $this->assertNotContains('test_stores:nike-store', $tags);

        // Command should also not recommend $cacheParents for custom owner key relations
        $command = new \KaziOmar\AtomicCache\Commands\CheckAtomicCacheCommand();
        $refMethod = new \ReflectionMethod($command, 'inspectModelBelongsToAdvisories');
        $advisories = $refMethod->invoke($command, TestCustomOwnerKeyModel::class, $model, []);
        $this->assertEmpty($advisories);
    }

    /**
     * Test 15: Method with "belongsTo" in comment but not returning belongsTo is never invoked by linter.
     */
    public function test_method_with_belongs_to_in_comment_is_not_invoked(): void
    {
        $model = new TestCommentBelongsToModel();

        $command = new \KaziOmar\AtomicCache\Commands\CheckAtomicCacheCommand();
        $refMethod = new \ReflectionMethod($command, 'inspectModelBelongsToAdvisories');
        $advisories = $refMethod->invoke($command, TestCommentBelongsToModel::class, $model, []);

        $this->assertEmpty($advisories);
        $this->assertFalse(TestCommentBelongsToModel::$commentMethodWasCalled, 'Method with belongsTo in comment was incorrectly executed!');
    }

    /**
     * Test 16: Edge Case - Database transaction rollback NEVER clears parent or child cache.
     */
    public function test_transaction_rollback_does_not_clear_parent_cache(): void
    {
        $store1 = TestStoreModel::create(['name' => 'Store One']);
        $store2 = TestStoreModel::create(['name' => 'Store Two']);
        $order = TestOrderModel::create(['store_id' => $store1->id, 'status' => 'pending']);

        AtomicCache::tags(["test_stores:{$store1->id}:test_orders"])->put('orders_list', 'cached_store_1', 3600);
        AtomicCache::tags(["test_stores:{$store2->id}:test_orders"])->put('orders_list', 'cached_store_2', 3600);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($order, $store2) {
                $order->update(['store_id' => $store2->id]);
                throw new \Exception('Simulated payment failure / exception forcing rollback');
            });
        } catch (\Throwable) {
            // Rollback occurred
        }

        // Neither store's cache should be cleared because transaction was aborted
        $this->assertEquals('cached_store_1', AtomicCache::tags(["test_stores:{$store1->id}:test_orders"])->get('orders_list'));
        $this->assertEquals('cached_store_2', AtomicCache::tags(["test_stores:{$store2->id}:test_orders"])->get('orders_list'));
    }

    /**
     * Test 17: Edge Case - Multiple updates on the same model in the same transaction (Store 1 -> 2 -> 3).
     */
    public function test_multiple_updates_in_same_transaction_invalidates_all_participating_parent_caches(): void
    {
        $store1 = TestStoreModel::create(['name' => 'Store 1']);
        $store2 = TestStoreModel::create(['name' => 'Store 2']);
        $store3 = TestStoreModel::create(['name' => 'Store 3']);
        $order = TestOrderModel::create(['store_id' => $store1->id, 'status' => 'pending']);

        AtomicCache::tags(["test_stores:{$store1->id}:test_orders"])->put('s1', 'val1', 3600);
        AtomicCache::tags(["test_stores:{$store2->id}:test_orders"])->put('s2', 'val2', 3600);
        AtomicCache::tags(["test_stores:{$store3->id}:test_orders"])->put('s3', 'val3', 3600);

        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $store2, $store3) {
            $order->update(['store_id' => $store2->id]);
            $order->update(['store_id' => $store3->id]);
        });

        // All 3 stores must be cleared: initial (1), intermediate (2), and final (3)
        $this->assertNull(AtomicCache::tags(["test_stores:{$store1->id}:test_orders"])->get('s1'));
        $this->assertNull(AtomicCache::tags(["test_stores:{$store2->id}:test_orders"])->get('s2'));
        $this->assertNull(AtomicCache::tags(["test_stores:{$store3->id}:test_orders"])->get('s3'));
    }

    /**
     * Test 18: Edge Case - Multiple parent relationships declared on a single model (e.g. ['store', 'product']).
     */
    public function test_multiple_parent_relations_on_single_model_clears_all_parents(): void
    {
        $store = TestStoreModel::create(['name' => 'Super Shop']);
        $product = TestProductModel::create(['name' => 'Laptop']);

        AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->put('store_orders', 'data1', 3600);
        AtomicCache::tags(["test_products:{$product->id}:test_orders"])->put('product_orders', 'data2', 3600);

        $order = TestMultiParentModel::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'status' => 'pending',
        ]);

        // Both store and product parent caches must be invalidated!
        $this->assertNull(AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->get('store_orders'));
        $this->assertNull(AtomicCache::tags(["test_products:{$product->id}:test_orders"])->get('product_orders'));
    }

    /**
     * Test 19: Edge Case - PHP BackedEnum foreign key values resolve cleanly without error on create and update.
     */
    public function test_backed_enum_foreign_key_resolves_cleanly(): void
    {
        $order = new TestOrderModel();
        $order->store_id = TestStoreEnum::Outlet;

        $tags = $order->resolveCacheTagsToClear();

        $this->assertContains('test_stores:77:test_orders', $tags);
        $this->assertContains('test_stores:77', $tags);

        // Test updating from one BackedEnum to another BackedEnum (Store 77 -> Store 88)
        $order->syncOriginal(); // Now original is Outlet (77)
        $order->store_id = TestStoreEnum::Flagship; // Now current is Flagship (88)

        $updateTags = $order->resolveCacheTagsToClear();
        $this->assertContains('test_stores:77:test_orders', $updateTags);
        $this->assertContains('test_stores:88:test_orders', $updateTags);
    }

    /**
     * Test 20: Edge Case - Force delete (permanent delete on SoftDeletes model) clears parent cache.
     */
    public function test_force_delete_clears_parent_cache(): void
    {
        $store = TestStoreModel::create(['name' => 'Outlet']);
        $order = TestOrderModel::create(['store_id' => $store->id, 'status' => 'active']);

        // First soft delete
        $order->delete();

        // Re-cache
        AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->put('items', 'cached_data', 3600);
        $this->assertEquals('cached_data', AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->get('items'));

        // Permanent force delete
        $order->forceDelete();

        // Parent cache must be invalidated
        $this->assertNull(AtomicCache::tags(["test_stores:{$store->id}:test_orders"])->get('items'));
    }

    /**
     * Test 21: In-memory class-level static cache eliminates reflection overhead on subsequent calls.
     */
    public function test_class_level_static_cache_eliminates_subsequent_reflection_overhead(): void
    {
        TestOrderModel::flushConfiguredParentRelationsCache();

        $ref = new \ReflectionClass(TestOrderModel::class);
        $prop = $ref->getProperty('configuredParentRelationsCache');

        // Verify cache is empty for TestOrderModel initially
        $this->assertArrayNotHasKey(TestOrderModel::class, $prop->getValue());

        $order = new TestOrderModel();
        $order->store_id = 15;

        // 1. First call resolves via reflection and caches in static array
        $tags1 = $order->resolveCacheTagsToClear();
        $this->assertContains('test_stores:15:test_orders', $tags1);

        // Verify static cache was populated with the resolved relations
        $cached = $prop->getValue();
        $this->assertArrayHasKey(TestOrderModel::class, $cached);
        $this->assertEquals(['store'], $cached[TestOrderModel::class]);

        // 2. Second call resolves directly from in-memory cache
        $order2 = new TestOrderModel();
        $order2->store_id = 20;
        $tags2 = $order2->resolveCacheTagsToClear();
        $this->assertContains('test_stores:20:test_orders', $tags2);

        // 3. Verify flushConfiguredParentRelationsCache() clears the static entry
        TestOrderModel::flushConfiguredParentRelationsCache();
        $this->assertArrayNotHasKey(TestOrderModel::class, $prop->getValue());
    }

    /**
     * Test 22: Private $cacheParents property is safely read without throwing reflection errors.
     */
    public function test_private_cache_parents_property_is_safely_read(): void
    {
        $model = new TestPrivateCacheParentsModel();
        $model->store_id = 99;

        $tags = $model->resolveCacheTagsToClear();
        $this->assertContains('test_stores:99:test_orders', $tags);
        $this->assertContains('test_stores:99', $tags);

        // Also verify linter can extract private property
        $command = new \KaziOmar\AtomicCache\Commands\CheckAtomicCacheCommand();
        $refMethod = new \ReflectionMethod($command, 'getDeclaredParentRelations');
        $declared = $refMethod->invoke($command, $model);
        $this->assertEquals(['store'], $declared);
    }

    /**
     * Test 23: clearCache() instance helper and flushCache() static helper manually invalidate model caches.
     */
    public function test_model_clear_cache_and_static_flush_cache(): void
    {
        // 1. Seed caches
        AtomicCache::tags(['test_orders'])->put('order_list', 'cached_list', 3600);
        AtomicCache::tags(['test_orders:5'])->put('order_5', 'cached_order_5', 3600);
        $this->assertEquals('cached_list', AtomicCache::tags(['test_orders'])->get('order_list'));
        $this->assertEquals('cached_order_5', AtomicCache::tags(['test_orders:5'])->get('order_5'));

        // 2. Test instance clearCache()
        $order = new TestOrderModel();
        $order->id = 5;
        $order->store_id = 1;
        $order->clearCache();

        $this->assertNull(AtomicCache::tags(['test_orders:5'])->get('order_5'));

        // 3. Re-seed collection and test static flushCache()
        AtomicCache::tags(['test_orders'])->put('order_list_2', 'cached_list_2', 3600);
        $this->assertEquals('cached_list_2', AtomicCache::tags(['test_orders'])->get('order_list_2'));

        TestOrderModel::flushCache();
        $this->assertNull(AtomicCache::tags(['test_orders'])->get('order_list_2'));
    }

    /**
     * Test 24: Strict mode throws InvalidArgumentException when a parent relation method does not exist.
     */
    public function test_strict_mode_throws_on_missing_relation_method(): void
    {
        config(['atomic-cache.strict_mode' => true]);
        TestStrictTypoModel::flushConfiguredParentRelationsCache();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Model [" . TestStrictTypoModel::class . "] declared cache parent relation ['nonExistentRelation'], but method nonExistentRelation() does not exist on the model.");

        $model = new TestStrictTypoModel();
        $model->resolveCacheTagsToClear();
    }

    /**
     * Test 25: Strict mode throws InvalidArgumentException when a parent relation method requires parameters.
     */
    public function test_strict_mode_throws_on_relation_method_with_required_parameters(): void
    {
        config(['atomic-cache.strict_mode' => true]);
        TestStrictParamModel::flushConfiguredParentRelationsCache();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Model [" . TestStrictParamModel::class . "] declared cache parent relation ['relationWithParam'], but method relationWithParam() requires parameters.");

        $model = new TestStrictParamModel();
        $model->resolveCacheTagsToClear();
    }

    /**
     * Test 26: Strict mode throws InvalidArgumentException when a parent relation is not a BelongsTo.
     */
    public function test_strict_mode_throws_on_non_belongs_to_relation(): void
    {
        config(['atomic-cache.strict_mode' => true]);
        TestStrictMorphModel::flushConfiguredParentRelationsCache();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Model [" . TestStrictMorphModel::class . "] declared cache parent relation ['morphRelation'], but relation is not an instance of BelongsTo.");

        $model = new TestStrictMorphModel();
        $model->resolveCacheTagsToClear();
    }

    /**
     * Test 27: When strict mode is disabled, faulty parent relations are gracefully skipped without throwing.
     */
    public function test_strict_mode_disabled_gracefully_skips_without_throwing(): void
    {
        config(['atomic-cache.strict_mode' => false]);
        TestStrictTypoModel::flushConfiguredParentRelationsCache();
        TestStrictParamModel::flushConfiguredParentRelationsCache();
        TestStrictMorphModel::flushConfiguredParentRelationsCache();

        $model1 = new TestStrictTypoModel();
        $tags1 = $model1->resolveCacheTagsToClear();
        $this->assertContains('test_strict_typo', $tags1);

        $model2 = new TestStrictParamModel();
        $tags2 = $model2->resolveCacheTagsToClear();
        $this->assertContains('test_strict_param', $tags2);

        $model3 = new TestStrictMorphModel();
        $tags3 = $model3->resolveCacheTagsToClear();
        $this->assertContains('test_strict_morph', $tags3);
    }

    /**
     * Test 28: $cacheIgnoreColumns preserves collection and parent tags on update when only ignored columns change.
     */
    public function test_cache_ignore_columns_preserves_collection_and_parent_tags_on_update(): void
    {
        $store = TestStoreModel::create(['name' => 'Flagship Store']);
        $product = TestProductWithIgnoredColumnsModel::create([
            'store_id' => $store->id,
            'title' => 'Original Title',
            'view_count' => 1,
            'meta' => 'v1',
        ]);

        // Seed caches
        $itemTag = "test_products_ignored:{$product->id}";
        $collectionTag = 'test_products_ignored';
        $parentCollectionTag = "test_stores:{$store->id}:test_products_ignored";
        $parentUmbrellaTag = "test_stores:{$store->id}";

        AtomicCache::tags([$itemTag])->put('item_key', 'cached_item', 3600);
        AtomicCache::tags([$collectionTag])->put('collection_key', 'cached_collection', 3600);
        AtomicCache::tags([$parentCollectionTag])->put('parent_col_key', 'cached_parent_col', 3600);
        AtomicCache::tags([$parentUmbrellaTag])->put('umbrella_key', 'cached_umbrella', 3600);

        // Verify all 4 cache layers are hit
        $this->assertEquals('cached_item', AtomicCache::tags([$itemTag])->get('item_key'));
        $this->assertEquals('cached_collection', AtomicCache::tags([$collectionTag])->get('collection_key'));
        $this->assertEquals('cached_parent_col', AtomicCache::tags([$parentCollectionTag])->get('parent_col_key'));
        $this->assertEquals('cached_umbrella', AtomicCache::tags([$parentUmbrellaTag])->get('umbrella_key'));

        // Update ONLY ignored columns (view_count and meta, plus auto updated_at)
        $product->view_count = 50;
        $product->meta = 'v2';
        $product->save();

        // 1. Single item tag IS cleared (so single item shows fresh view count)
        $this->assertNull(AtomicCache::tags([$itemTag])->get('item_key'));

        // 2. Heavy collection & parent tags are NOT cleared (protected by cacheIgnoreColumns)
        $this->assertEquals('cached_collection', AtomicCache::tags([$collectionTag])->get('collection_key'));
        $this->assertEquals('cached_parent_col', AtomicCache::tags([$parentCollectionTag])->get('parent_col_key'));
        $this->assertEquals('cached_umbrella', AtomicCache::tags([$parentUmbrellaTag])->get('umbrella_key'));
    }

    /**
     * Test 29: $cacheIgnoreColumns flushes collection and parent tags when non-ignored columns change.
     */
    public function test_cache_ignore_columns_flushes_collection_when_non_ignored_columns_change(): void
    {
        $store = TestStoreModel::create(['name' => 'Flagship Store 2']);
        $product = TestProductWithIgnoredColumnsModel::create([
            'store_id' => $store->id,
            'title' => 'Original Title 2',
            'view_count' => 1,
        ]);

        $collectionTag = 'test_products_ignored';
        $parentCollectionTag = "test_stores:{$store->id}:test_products_ignored";

        AtomicCache::tags([$collectionTag])->put('collection_key_2', 'cached_col_2', 3600);
        AtomicCache::tags([$parentCollectionTag])->put('parent_col_key_2', 'cached_p_2', 3600);

        // Update title (non-ignored column) alongside view_count
        $product->update([
            'title' => 'Changed Title',
            'view_count' => 100,
        ]);

        // Both collection and parent collection MUST be invalidated
        $this->assertNull(AtomicCache::tags([$collectionTag])->get('collection_key_2'));
        $this->assertNull(AtomicCache::tags([$parentCollectionTag])->get('parent_col_key_2'));
    }

    /**
     * Test 30: $cacheIgnoreColumns always clears collection tags on model create and delete.
     */
    public function test_cache_ignore_columns_always_clears_on_create_and_delete(): void
    {
        $collectionTag = 'test_products_ignored';

        // 1. Create always invalidates collection
        AtomicCache::tags([$collectionTag])->put('col_create_key', 'cached_before_create', 3600);
        $this->assertEquals('cached_before_create', AtomicCache::tags([$collectionTag])->get('col_create_key'));

        $product = TestProductWithIgnoredColumnsModel::create([
            'title' => 'Created Item',
            'view_count' => 0,
        ]);

        $this->assertNull(AtomicCache::tags([$collectionTag])->get('col_create_key'));

        // 2. Delete always invalidates collection
        AtomicCache::tags([$collectionTag])->put('col_delete_key', 'cached_before_delete', 3600);
        $this->assertEquals('cached_before_delete', AtomicCache::tags([$collectionTag])->get('col_delete_key'));

        $product->delete();

        $this->assertNull(AtomicCache::tags([$collectionTag])->get('col_delete_key'));
    }

    /**
     * Test 31: Query macro ->atomicCache(scope: ...) isolates cache so mutations in store 2 do not invalidate store 1.
     */
    public function test_query_macro_with_scope_isolates_invalidation_across_tenants(): void
    {
        $store1 = TestStoreModel::create(['name' => 'Store 1']);
        $store2 = TestStoreModel::create(['name' => 'Store 2']);

        $order1 = TestOrderModel::create(['store_id' => $store1->id, 'status' => 'pending']);
        $order2 = TestOrderModel::create(['store_id' => $store2->id, 'status' => 'pending']);

        $scope1 = "test_stores:{$store1->id}:test_orders";
        $scope2 = "test_stores:{$store2->id}:test_orders";

        // Query with scope:
        $res1 = TestOrderModel::where('store_id', $store1->id)->atomicCache(scope: $scope1);
        $res2 = TestOrderModel::where('store_id', $store2->id)->atomicCache(scope: $scope2);

        $this->assertCount(1, $res1);
        $this->assertCount(1, $res2);

        // Mutate Store 2 by creating a new order in Store 2
        TestOrderModel::create(['store_id' => $store2->id, 'status' => 'completed']);

        // Verify Store 2 cache was flushed, but Store 1 cache remained completely warm!
        // We can verify Store 1 didn't re-execute by inserting a row in Store 1 directly via DB (bypassing events)
        \Illuminate\Support\Facades\DB::table('test_orders')->insert([
            'store_id' => $store1->id,
            'status' => 'stealth_order',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Calling Store 1 query MUST still return 1 order because its cache was NOT invalidated!
        $cachedStore1 = TestOrderModel::where('store_id', $store1->id)->atomicCache(scope: $scope1);
        $this->assertCount(1, $cachedStore1, 'Store 1 cache should remain warm after store 2 mutation');

        // Calling Store 2 query MUST return 2 orders because its cache WAS invalidated!
        $freshStore2 = TestOrderModel::where('store_id', $store2->id)->atomicCache(scope: $scope2);
        $this->assertCount(2, $freshStore2, 'Store 2 cache should have been invalidated and reloaded');
    }

    /**
     * Test 32: ->atomicCacheFirst(scope: ...) correctly uses scoped tags.
     */
    public function test_query_macro_first_with_scope(): void
    {
        $store = TestStoreModel::create(['name' => 'First Scope Store']);
        $order = TestOrderModel::create(['store_id' => $store->id, 'status' => 'initial_first']);

        $scope = "test_stores:{$store->id}:test_orders";

        $cachedFirst = TestOrderModel::where('store_id', $store->id)->atomicCacheFirst(scope: $scope);
        $this->assertEquals('initial_first', $cachedFirst->status);

        // Update the order in store
        $order->update(['status' => 'updated_first']);

        // Cache must have been invalidated via $cacheParents
        $reloadedFirst = TestOrderModel::where('store_id', $store->id)->atomicCacheFirst(scope: $scope);
        $this->assertEquals('updated_first', $reloadedFirst->status);
    }
}
