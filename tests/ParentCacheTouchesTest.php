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
}
