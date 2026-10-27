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
    }

    protected function tearDown(): void
    {
        TestOrderModel::flushConfiguredParentRelationsCache();
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
}

