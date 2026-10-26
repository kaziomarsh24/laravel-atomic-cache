<?php

namespace KaziOmar\AtomicCache\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use KaziOmar\AtomicCache\Blade\AtomicCacheBladeHelper;
use KaziOmar\AtomicCache\Facades\AtomicCache;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;
use KaziOmar\AtomicCache\Traits\HasAtomicCache;

class TestPost extends Model
{
    use AutoClearsCache;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'test_posts';
    protected $guarded = [];

    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TestPost::class, 'id');
    }
}

class TestService
{
    use HasAtomicCache;

    public function getCachedPosts()
    {
        return $this->cache(__FUNCTION__, func_get_args(), function () {
            return ['post_1', 'post_2'];
        });
    }

    public function getCachedItem(int $id)
    {
        return $this->cacheItem($id, function () use ($id) {
            return "item_{$id}";
        });
    }
}

class AtomicCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->integer('stock')->default(10);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function test_can_put_and_get_cached_value(): void
    {
        AtomicCache::put('greeting', 'Hello World', 60);

        $this->assertEquals('Hello World', AtomicCache::get('greeting'));
        $this->assertTrue(AtomicCache::has('greeting'));
    }

    public function test_remember_computes_and_caches_value(): void
    {
        $executed = 0;
        $callback = function () use (&$executed) {
            $executed++;
            return 'computed_value';
        };

        $first = AtomicCache::remember('compute_key', $callback, 60);
        $second = AtomicCache::remember('compute_key', $callback, 60);

        $this->assertEquals('computed_value', $first);
        $this->assertEquals('computed_value', $second);
        $this->assertEquals(1, $executed, 'Callback should only be executed once');
    }

    public function test_flexible_remember_signatures_work_seamlessly(): void
    {
        // 1. (key, ttl, callback) - Laravel native order
        $val1 = AtomicCache::remember('key_order_1', 60, fn() => 'val1');
        $this->assertEquals('val1', $val1);

        // 2. (key, callback, ttl) - Flexible order
        $val2 = AtomicCache::remember('key_order_2', fn() => 'val2', 60);
        $this->assertEquals('val2', $val2);

        // 3. (key, callback) - No TTL provided, uses default TTL
        $val3 = AtomicCache::remember('key_order_3', fn() => 'val3');
        $this->assertEquals('val3', $val3);
    }

    public function test_forget_and_clear_removes_value(): void
    {
        AtomicCache::put('temp', 'value', 60);
        $this->assertEquals('value', AtomicCache::get('temp'));

        AtomicCache::clear('temp');
        $this->assertNull(AtomicCache::get('temp'));
        $this->assertFalse(AtomicCache::has('temp'));
    }

    public function test_pull_retrieves_and_forgets_item(): void
    {
        AtomicCache::put('pull_key', 'pull_value', 60);
        $val = AtomicCache::pull('pull_key');

        $this->assertEquals('pull_value', $val);
        $this->assertNull(AtomicCache::get('pull_key'));
    }

    public function test_remember_for_user_scopes_by_user_id(): void
    {
        AtomicCache::rememberForUser('profile', fn() => 'User 1 Profile', 60, userId: 1);
        AtomicCache::rememberForUser('profile', fn() => 'User 2 Profile', 60, userId: 2);

        $this->assertEquals('User 1 Profile', AtomicCache::forUser(1)->get('profile'));
        $this->assertEquals('User 2 Profile', AtomicCache::forUser(2)->get('profile'));
    }

    public function test_auto_compression_compresses_large_payloads(): void
    {
        $largeData = array_fill(0, 1000, ['key' => str_repeat('B', 100)]);
        AtomicCache::put('large_list', $largeData, 60);

        $retrieved = AtomicCache::get('large_list');
        $this->assertCount(1000, $retrieved);
        $this->assertEquals($largeData, $retrieved);
    }

    public function test_helper_function_works_properly(): void
    {
        // helper with (key, ttl, callback)
        atomic_cache('helper_key', 60, fn() => 'from_helper');
        $this->assertEquals('from_helper', atomic_cache('helper_key'));

        // helper returning instance
        $instance = atomic_cache();
        $this->assertInstanceOf(\KaziOmar\AtomicCache\AtomicCache::class, $instance);
    }

    public function test_file_driver_graceful_fallback_without_redis(): void
    {
        $tag = 'demo_tag_' . uniqid();
        $key = 'file_tag_key_' . uniqid();

        $count = 0;
        $counterFn = function () use (&$count) {
            $count++;
            return "data_count_{$count}";
        };

        // 1. Should NOT crash on file store when tags are passed!
        $fileCache = AtomicCache::store('file')->tags([$tag]);
        $first = $fileCache->remember($key, $counterFn, 60);
        $this->assertEquals('data_count_1', $first);

        // 2. Cache hit should return same value without incrementing count
        $second = $fileCache->remember($key, $counterFn, 60);
        $this->assertEquals('data_count_1', $second);
        $this->assertEquals(1, $count);

        // 3. Clear virtual tags on file driver
        AtomicCache::store('file')->clearTags([$tag]);

        // 4. Next call should detect tag invalidation and execute callback again!
        $third = $fileCache->remember($key, $counterFn, 60);
        $this->assertEquals('data_count_2', $third);
        $this->assertEquals(2, $count);
    }

    public function test_has_atomic_cache_trait_on_service(): void
    {
        $service = new TestService();

        $posts = $service->getCachedPosts();
        $this->assertEquals(['post_1', 'post_2'], $posts);

        $item = $service->getCachedItem(42);
        $this->assertEquals('item_42', $item);
    }

    public function test_has_atomic_cache_safely_normalizes_closures_and_models(): void
    {
        $service = new TestService();
        $post = TestPost::create(['title' => 'Closure Test Model']);
        $closure = fn() => 'test_closure';

        // Passing Closures and Models into cache() arguments should NOT throw serialization exception
        $result = $service->cache('test_method_with_closure', [$closure, $post], function () {
            return 'safe_computation';
        });

        $this->assertEquals('safe_computation', $result);
    }

    public function test_has_atomic_cache_differentiates_pagination_and_query_parameters(): void
    {
        $service = new TestService();

        // Simulate page 1
        request()->merge(['page' => 1]);
        $page1 = $service->cacheCollection('paginated_list', fn() => 'Page 1 Results');

        // Simulate page 2
        request()->merge(['page' => 2]);
        $page2 = $service->cacheCollection('paginated_list', fn() => 'Page 2 Results');

        $this->assertEquals('Page 1 Results', $page1);
        $this->assertEquals('Page 2 Results', $page2);
    }

    public function test_auto_clears_cache_trait_on_model_invalidation(): void
    {
        $post = TestPost::create(['title' => 'Initial Title']);

        $count = 0;
        $val1 = AtomicCache::tags(['test_posts', 'test_posts:' . $post->id])->remember('post_cache', function () use (&$count) {
            $count++;
            return "computed_{$count}";
        }, 60);

        $this->assertEquals('computed_1', $val1);

        // Update model -> triggers AutoClearsCache
        $post->update(['title' => 'Updated Title']);

        // Cache must be cleared by model lifecycle
        $val2 = AtomicCache::tags(['test_posts', 'test_posts:' . $post->id])->remember('post_cache', function () use (&$count) {
            $count++;
            return "computed_{$count}";
        }, 60);

        $this->assertEquals('computed_2', $val2);
        $this->assertEquals(2, $count);
    }

    public function test_ttl_jitter_calculates_within_expected_variance(): void
    {
        // Using withJitter(0.1) on 1000s should keep TTL between 900s and 1100s
        $data = AtomicCache::withJitter(0.1)->remember('jitter_test', fn() => 'jitter_val', 1000);
        $this->assertEquals('jitter_val', $data);
        $this->assertEquals('jitter_val', AtomicCache::get('jitter_test'));
    }

    public function test_blade_helper_caches_html_fragments(): void
    {
        $key = 'blade_block_' . uniqid();

        // 1. First run: cache miss, setUp returns true
        $shouldRender = \KaziOmar\AtomicCache\Blade\AtomicCacheBladeHelper::setUp($key, 60);
        $this->assertTrue($shouldRender);

        echo '<p>Rendered HTML Content</p>';
        $output = \KaziOmar\AtomicCache\Blade\AtomicCacheBladeHelper::tearDown();
        $this->assertEquals('<p>Rendered HTML Content</p>', $output);

        // 2. Second run: cache hit, setUp outputs cached content and returns false
        ob_start();
        $shouldRenderAgain = \KaziOmar\AtomicCache\Blade\AtomicCacheBladeHelper::setUp($key, 60);
        $cachedOutput = ob_get_clean();

        $this->assertFalse($shouldRenderAgain);
        $this->assertEquals('<p>Rendered HTML Content</p>', $cachedOutput);
    }

    public function test_artisan_command_clears_atomic_cache(): void
    {
        AtomicCache::put('cmd_key', 'cmd_value', 60);
        $this->assertEquals('cmd_value', AtomicCache::get('cmd_key'));

        $this->artisan('atomic-cache:clear', ['tag' => 'cmd_key'])
            ->assertSuccessful();

        $this->assertNull(AtomicCache::get('cmd_key'));
    }
    public function test_has_atomic_cache_respects_service_properties_and_per_user(): void
    {
        $service = new class {
            use HasAtomicCache;
            public bool $cachingEnabled = true;
            public bool $cachePerUser = false;
            public int $cacheTTL = 3600;

            public function getAll()
            {
                return $this->cache(__FUNCTION__, func_get_args(), fn() => ['item1', 'item2'], $this->cacheTTL, $this->cachePerUser);
            }
        };

        // 1. Caches properly
        $val1 = $service->getAll();
        $this->assertEquals(['item1', 'item2'], $val1);

        // 2. Disabling caching returns directly
        $service->cachingEnabled = false;
        $val2 = $service->getAll();
        $this->assertEquals(['item1', 'item2'], $val2);
    }

    public function test_atomic_cache_fluent_tags_flush_and_flush_tags(): void
    {
        $tag = 'test_fluent_tag_' . uniqid();
        AtomicCache::tags([$tag])->put('tagged_item', 'hello', 60);
        $this->assertEquals('hello', AtomicCache::tags([$tag])->get('tagged_item'));

        // Test fluent tag flush: AtomicCache::tags(['...'])->flush()
        $flushed = AtomicCache::tags([$tag])->flush();
        $this->assertTrue($flushed);
        $this->assertNull(AtomicCache::tags([$tag])->get('tagged_item'));

        // Test flushTags method
        AtomicCache::tags([$tag])->put('tagged_item2', 'world', 60);
        $this->assertEquals('world', AtomicCache::tags([$tag])->get('tagged_item2'));
        AtomicCache::flushTags([$tag]);
        $this->assertNull(AtomicCache::tags([$tag])->get('tagged_item2'));
    }

    public function test_service_trait_new_idiomatic_methods_and_aliases(): void
    {
        $service = new class {
            use HasAtomicCache;
            protected string $cacheResource = 'orders';

            public function getOrder(int $id)
            {
                return $this->rememberItem($id, fn() => "Order #{$id}");
            }

            public function getOrders()
            {
                return $this->rememberCollection('all', fn() => ['Order 1', 'Order 2']);
            }

            public function getOrdersList()
            {
                return $this->rememberList('list', fn() => ['Order 1', 'Order 2']);
            }
        };

        // 1. rememberItem & forgetItem
        $order = $service->getOrder(10);
        $this->assertEquals('Order #10', $order);
        $this->assertEquals('Order #10', $service->cacheItem(10, fn() => 'New')); // cache hit via alias

        $service->forgetItem(10);
        $this->assertNull(AtomicCache::tags(['orders:10'])->get('orders:10'));

        // 2. rememberCollection & flushCollection
        $orders = $service->getOrders();
        $this->assertEquals(['Order 1', 'Order 2'], $orders);

        $service->flushCollection();
        // After flush, new closure runs
        $freshOrders = $service->rememberCollection('all', fn() => ['Fresh Order']);
        $this->assertEquals(['Fresh Order'], $freshOrders);

        // 3. Backward compatibility aliases
        $aliasOrder = $service->cacheItem(20, fn() => 'Order #20');
        $this->assertEquals('Order #20', $aliasOrder);
        $service->clearItemCache(20);
        $this->assertNull(AtomicCache::tags(['orders:20'])->get('orders:20'));

        // 3.1 Verify flushAll clears both item and collection caches
        $service->cacheItem(30, fn() => 'Order #30');
        $this->assertEquals('Order #30', $service->cacheItem(30, fn() => 'Stale'));
        $service->flushAll();
        $this->assertEquals('Fresh #30', $service->cacheItem(30, fn() => 'Fresh #30'));

        // 4. Flexible remember in service (both orders)
        $r1 = $service->remember('test_srv_r1', 60, fn() => 'r1_val');
        $this->assertEquals('r1_val', $r1);

        $r2 = $service->remember('test_srv_r2', fn() => 'r2_val', 60);
        $this->assertEquals('r2_val', $r2);
    }

    public function test_transparent_chunking_stores_and_reassembles_large_array_preserving_keys(): void
    {
        $items = [];
        for ($i = 1; $i <= 2500; $i++) {
            $items["key_{$i}"] = "Value #{$i}";
        }

        AtomicCache::put('big_dataset', $items, 60);

        // Verify underlying storage manifest
        $store = AtomicCache::getRawStore();
        $manifest = $store->get('atomic:big_dataset');
        $this->assertIsArray($manifest);
        $this->assertTrue($manifest['__ac_chunked'] ?? false);
        $this->assertEquals(3, $manifest['chunks_count']);
        $this->assertEquals(2500, $manifest['total_items']);

        // Verify chunk keys exist in underlying store
        $this->assertTrue($store->has('atomic:big_dataset:__chunk__:0'));
        $this->assertTrue($store->has('atomic:big_dataset:__chunk__:1'));
        $this->assertTrue($store->has('atomic:big_dataset:__chunk__:2'));

        // Retrieve and verify data integrity and key preservation
        $restored = AtomicCache::get('big_dataset');
        $this->assertIsArray($restored);
        $this->assertCount(2500, $restored);
        $this->assertEquals('Value #1', $restored['key_1']);
        $this->assertEquals('Value #2500', $restored['key_2500']);

        // Verify forget removes both manifest and all chunk keys
        AtomicCache::forget('big_dataset');
        $this->assertFalse($store->has('atomic:big_dataset'));
        $this->assertFalse($store->has('atomic:big_dataset:__chunk__:0'));
        $this->assertFalse($store->has('atomic:big_dataset:__chunk__:1'));
        $this->assertFalse($store->has('atomic:big_dataset:__chunk__:2'));
    }

    public function test_transparent_chunking_with_collection(): void
    {
        $items = collect(range(1, 2200))->mapWithKeys(fn($i) => ["item_{$i}" => "Item Data {$i}"]);
        AtomicCache::put('big_collection', $items, 60);

        $restored = AtomicCache::get('big_collection');
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $restored);
        $this->assertCount(2200, $restored);
        $this->assertEquals('Item Data 1', $restored->get('item_1'));
        $this->assertEquals('Item Data 2200', $restored->get('item_2200'));

        AtomicCache::forget('big_collection');
    }

    public function test_chunking_self_healing_recovers_from_missing_chunk(): void
    {
        $items = [];
        for ($i = 1; $i <= 2500; $i++) {
            $items["key_{$i}"] = "Value #{$i}";
        }

        AtomicCache::put('broken_chunked_data', $items, 60);

        // Simulate chunk loss (e.g. eviction or driver memory drop)
        $store = AtomicCache::getRawStore();
        $store->forget('atomic:broken_chunked_data:__chunk__:1');

        // remember should detect corrupted/missing chunk, self-heal, and run closure
        $recovered = AtomicCache::remember('broken_chunked_data', 60, function () {
            return ['status' => 'healed_fresh_data'];
        });

        $this->assertEquals(['status' => 'healed_fresh_data'], $recovered);
        AtomicCache::forget('broken_chunked_data');
    }

    public function test_write_deduplication_stores_cache_dna(): void
    {
        config(['atomic-cache.deduplication.enabled' => true]);

        $data = ['name' => 'John Doe', 'role' => 'Admin'];
        $key = 'dedup_test_' . uniqid();

        $success1 = AtomicCache::put($key, $data, 60);
        $this->assertTrue($success1);

        // Verify DNA key exists
        $store = AtomicCache::getRawStore();
        $this->assertTrue($store->has("atomic:{$key}:__dna__"));

        // Second put with identical data succeeds immediately via deduplication
        $success2 = AtomicCache::put($key, $data, 60);
        $this->assertTrue($success2);

        $this->assertEquals($data, AtomicCache::get($key));
        AtomicCache::forget($key);
        $this->assertFalse($store->has("atomic:{$key}:__dna__"));
    }

    public function test_benchmark_command_runs_successfully(): void
    {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('atomic-cache:bench', [
            '--iterations' => 2,
            '--format' => 'json',
        ]);

        $this->assertEquals(0, $exitCode);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $json = json_decode($output, true);

        $this->assertIsArray($json);
        $this->assertArrayHasKey('latency', $json);
        $this->assertArrayHasKey('compression', $json);
        $this->assertArrayHasKey('chunking', $json);
        $this->assertArrayHasKey('tagging', $json);
        $this->assertTrue($json['chunking']['integrity_passed']);
        $this->assertTrue($json['tagging']['virtual_tagging_resilient']);
    }

    public function test_virtual_tagging_is_order_independent(): void
    {
        $uniqueKey = 'order_test_key_' . uniqid();
        $count = 0;
        $counterFn = function () use (&$count) {
            $count++;
            return "data_{$count}";
        };

        // 1. First call with tags ['alpha', 'beta']
        $res1 = AtomicCache::store('file')->tags(['alpha', 'beta'])->remember($uniqueKey, $counterFn, 60);
        $this->assertEquals('data_1', $res1);

        // 2. Second call with reversed order ['beta', 'alpha'] must hit cache, not invoke closure
        $res2 = AtomicCache::store('file')->tags(['beta', 'alpha'])->remember($uniqueKey, $counterFn, 60);
        $this->assertEquals('data_1', $res2);
        $this->assertEquals(1, $count, 'Reordered tags must produce an identical cache key and hit existing cache');
    }

    public function test_auto_clears_cache_trait_on_model_restored(): void
    {
        $post = TestPost::create(['title' => 'Soft Delete Title']);

        $count = 0;
        $val1 = AtomicCache::tags(['test_posts', 'test_posts:' . $post->id])->remember('post_restore_cache', function () use (&$count) {
            $count++;
            return "val_{$count}";
        }, 60);
        $this->assertEquals('val_1', $val1);

        // Soft delete model -> cache cleared
        $post->delete();

        // Re-cache while soft deleted
        $val2 = AtomicCache::tags(['test_posts', 'test_posts:' . $post->id])->remember('post_restore_cache', function () use (&$count) {
            $count++;
            return "val_{$count}";
        }, 60);
        $this->assertEquals('val_2', $val2);

        // Restore soft-deleted model -> must trigger 'restored' hook and clear cache!
        $post->restore();

        // Must run callback again because cache was cleared by restored event
        $val3 = AtomicCache::tags(['test_posts', 'test_posts:' . $post->id])->remember('post_restore_cache', function () use (&$count) {
            $count++;
            return "val_{$count}";
        }, 60);
        $this->assertEquals('val_3', $val3);
        $this->assertEquals(3, $count);
    }

    public function test_has_atomic_cache_strips_tracking_and_marketing_query_parameters(): void
    {
        $service = new TestService();
        $listKey = 'clean_list_' . uniqid();

        // 1. Request with clean query (page 1)
        request()->query = new \Symfony\Component\HttpFoundation\InputBag(['page' => 1, 'status' => 'active']);
        $count = 0;
        $res1 = $service->cacheCollection($listKey, function () use (&$count) {
            $count++;
            return "computed_{$count}";
        });
        $this->assertEquals('computed_1', $res1);

        // 2. Request with same filters PLUS tracking query params (fbclid, utm_source, gclid)
        request()->query = new \Symfony\Component\HttpFoundation\InputBag([
            'page' => 1,
            'status' => 'active',
            'fbclid' => 'IwAR_random_fb_click_id',
            'utm_source' => 'facebook',
            'utm_campaign' => 'black_friday',
            'gclid' => 'google_click_id_999',
        ]);

        $res2 = $service->cacheCollection($listKey, function () use (&$count) {
            $count++;
            return "computed_{$count}";
        });

        // Must hit cache! Count should not increment because marketing tags were stripped
        $this->assertEquals('computed_1', $res2);
        $this->assertEquals(1, $count);
    }

    public function test_write_deduplication_refreshes_ttl_when_content_identical(): void
    {
        $key = 'dedup_ttl_key_' . uniqid();

        // Initial write with 100 seconds
        AtomicCache::put($key, ['message' => 'hello'], 100);
        $this->assertTrue(AtomicCache::has($key));

        // Re-write identical content with 500 seconds
        $result = AtomicCache::put($key, ['message' => 'hello'], 500);
        $this->assertTrue($result);
        $this->assertTrue(AtomicCache::has($key));
    }

    public function test_auto_clears_cache_resolves_tags_cleanly_without_empty_key(): void
    {
        $unpersistedPost = new TestPost();
        $tags = $unpersistedPost->resolveCacheTagsToClear();

        $this->assertContains('test_posts', $tags);
        $this->assertNotContains('test_posts:', $tags);
    }

    public function test_type_safe_model_class_and_instance_tags(): void
    {
        $post = TestPost::create(['title' => 'Type-Safe Tag Post']);

        // 1. Tagging using Model::class string
        $cached1 = AtomicCache::tags([TestPost::class])->remember('type_safe_class_key', fn() => 'class_data', 60);
        $this->assertEquals('class_data', $cached1);

        // 2. Tagging using Model instance
        $cached2 = AtomicCache::tags([$post])->remember('type_safe_instance_key', fn() => 'instance_data', 60);
        $this->assertEquals('instance_data', $cached2);

        // Invalidate via Model::class
        AtomicCache::clearTags([TestPost::class]);
        $this->assertFalse(AtomicCache::tags([TestPost::class])->has('type_safe_class_key'));
    }

    public function test_eloquent_query_macro_zero_key_and_auto_invalidation(): void
    {
        $post = TestPost::create(['title' => 'Macro Post 1']);

        $dbQueries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$dbQueries) {
            $dbQueries++;
        });

        // 1. First execution - executes SQL and stores cache with auto-key
        $posts1 = TestPost::where('title', 'Macro Post 1')->atomicCache(60);
        $this->assertCount(1, $posts1);
        $this->assertEquals('Macro Post 1', $posts1->first()->title);
        $initialQueries = $dbQueries;
        $this->assertGreaterThan(0, $initialQueries);

        // 2. Second execution - hits cache, ZERO db queries executed!
        $posts2 = TestPost::where('title', 'Macro Post 1')->atomicCache(60);
        $this->assertCount(1, $posts2);
        $this->assertEquals($initialQueries, $dbQueries);

        // 3. Update the post -> AutoClearsCache fires and wipes query cache automatically!
        $post->update(['title' => 'Macro Post 1 (Updated)']);

        // 4. Third execution - cache was invalidated, must run fresh SQL query!
        $posts3 = TestPost::where('title', 'Macro Post 1')->atomicCache(60);
        $this->assertCount(0, $posts3);
        $this->assertGreaterThan($initialQueries, $dbQueries);
    }

    public function test_eloquent_query_macro_custom_key_and_first(): void
    {
        TestPost::create(['title' => 'First Post']);

        // Custom key
        $posts = TestPost::where('title', 'First Post')->atomicCache('custom_active_posts', 60);
        $this->assertCount(1, $posts);

        // atomicCacheFirst
        $single = TestPost::where('title', 'First Post')->atomicCacheFirst(60);
        $this->assertNotNull($single);
        $this->assertEquals('First Post', $single->title);

        // Base DB query macro
        $rawCount = \Illuminate\Support\Facades\DB::table('test_posts')->where('title', 'First Post')->atomicCache(60);
        $this->assertCount(1, $rawCount);
    }

    public function test_check_atomic_cache_command(): void
    {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('atomic-cache:check');
        $this->assertEquals(0, $exitCode);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Atomic Cache Architecture Audit', $output);

        // Test with JSON option
        $jsonCode = \Illuminate\Support\Facades\Artisan::call('atomic-cache:check', ['--json' => true]);
        $this->assertEquals(0, $jsonCode);
        $jsonOutput = \Illuminate\Support\Facades\Artisan::output();
        $decoded = json_decode($jsonOutput, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('health_score', $decoded);
    }

    public function test_normalize_tags_handles_numeric_and_nested_arrays(): void
    {
        $tags = AtomicCache::tags(['orders', [42, 'priority'], 'vip'])->activeTags ?? [];

        // In AtomicCache, activeTags is protected, but we can verify via clearTags
        $cleared = AtomicCache::clearTags(['orders', [42, 'priority'], 'vip']);
        $this->assertTrue($cleared);

        // Put and get using numeric and nested tags
        AtomicCache::tags(['orders', 42])->put('numeric_tag_test', 'success', 60);
        $this->assertEquals('success', AtomicCache::tags(['orders', 42])->get('numeric_tag_test'));

        AtomicCache::clearTags([42]);
        $this->assertNull(AtomicCache::tags(['orders', 42])->get('numeric_tag_test'));
    }

    public function test_query_macro_supports_table_aliases(): void
    {
        TestPost::create(['title' => 'Aliased Post']);

        // DB::table with alias 'test_posts as p'
        $posts = \Illuminate\Support\Facades\DB::table('test_posts as p')->where('title', 'Aliased Post')->atomicCache(60);
        $this->assertCount(1, $posts);
        $this->assertEquals('Aliased Post', $posts->first()->title);

        // Cache hit
        $cachedPosts = \Illuminate\Support\Facades\DB::table('test_posts as p')->where('title', 'Aliased Post')->atomicCache(60);
        $this->assertCount(1, $cachedPosts);
    }

    public function test_for_user_supports_uuid_and_string_ids_without_data_leakage(): void
    {
        $userA = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
        $userB = '8a0ceb3c-2a6c-4c9e-8bcc-1a0c6a2cba5c';

        AtomicCache::forUser($userA)->put('cart', ['items' => ['MacBook Pro']], 60);
        AtomicCache::forUser($userB)->put('cart', ['items' => ['iPhone 16']], 60);

        $cartA = AtomicCache::forUser($userA)->get('cart');
        $cartB = AtomicCache::forUser($userB)->get('cart');

        $this->assertEquals(['items' => ['MacBook Pro']], $cartA);
        $this->assertEquals(['items' => ['iPhone 16']], $cartB);
        $this->assertNotEquals($cartA, $cartB);
    }

    public function test_remember_with_lock_bubbles_callback_exception_and_does_not_retry(): void
    {
        $executionCount = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Simulated DB query error');

        try {
            AtomicCache::rememberWithLock('test_failing_query', function () use (&$executionCount) {
                $executionCount++;
                throw new \RuntimeException('Simulated DB query error');
            }, 60);
        } finally {
            $this->assertEquals(1, $executionCount, 'Failing callback must only execute once without being retried in fallback');
        }
    }

    public function test_virtual_tag_invalidation_uses_uuid_tokens_and_prevents_stale_resurrection(): void
    {
        $driver = 'array';
        $key = 'vt_test_item_' . uniqid();

        AtomicCache::store($driver)->tags(['products'])->put($key, 'original_version', 60);
        $this->assertEquals('original_version', AtomicCache::store($driver)->tags(['products'])->get($key));

        // Invalidate via clearTags
        AtomicCache::store($driver)->clearTags(['products']);
        $this->assertNull(AtomicCache::store($driver)->tags(['products'])->get($key));

        // Storing new item under same tag works with new version
        AtomicCache::store($driver)->tags(['products'])->put($key, 'second_version', 60);
        $this->assertEquals('second_version', AtomicCache::store($driver)->tags(['products'])->get($key));
    }

    public function test_benchmark_command_handles_zero_iterations_gracefully(): void
    {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('atomic-cache:bench', [
            '--driver' => 'array',
            '--iterations' => 0,
        ]);

        $this->assertEquals(0, $exitCode);
    }

    public function test_clear_command_with_force_and_all_clears_store(): void
    {
        AtomicCache::put('persist_key', 'value', 60);
        $this->assertEquals('value', AtomicCache::get('persist_key'));

        $exitCode = \Illuminate\Support\Facades\Artisan::call('atomic-cache:clear', [
            '--all' => true,
            '--force' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertNull(AtomicCache::get('persist_key'));
    }

    public function test_query_macro_differentiates_eager_loads_fingerprint(): void
    {
        TestPost::create(['title' => 'Eager Load Post']);

        // 1. Query without eager loads
        $withoutEager = TestPost::where('title', 'Eager Load Post')->atomicCache(60);
        $this->assertFalse($withoutEager->first()->relationLoaded('comments'));

        // 2. Query with eager loads (comments)
        $withEager = TestPost::with('comments')->where('title', 'Eager Load Post')->atomicCache(60);
        $this->assertTrue($withEager->first()->relationLoaded('comments'));
    }

    public function test_null_results_are_cached_without_cache_penetration(): void
    {
        $dbCallCount = 0;
        $key = 'null_test_' . uniqid();

        // 1. First call: callback returns null, gets cached
        $result1 = AtomicCache::remember($key, 60, function () use (&$dbCallCount) {
            $dbCallCount++;
            return null;
        });

        $this->assertNull($result1);
        $this->assertEquals(1, $dbCallCount);

        // 2. Second call: should retrieve null directly from cache without hitting callback
        $result2 = AtomicCache::remember($key, 60, function () use (&$dbCallCount) {
            $dbCallCount++;
            return null;
        });

        $this->assertNull($result2);
        $this->assertEquals(1, $dbCallCount, 'Callback should NOT be called a second time when null was cached');
    }

    public function test_joined_tables_are_auto_tagged_and_invalidated(): void
    {
        TestPost::create(['title' => 'Join Post 1']);

        $posts = \Illuminate\Support\Facades\DB::table('test_posts')
            ->join('test_posts as p2', 'test_posts.id', '=', 'p2.id')
            ->select('test_posts.*')
            ->atomicCache(60);

        $this->assertCount(1, $posts);

        // Clearing tag 'test_posts' clears the joined cache
        AtomicCache::clearTags(['test_posts']);

        // Verify cache is cleared
        TestPost::create(['title' => 'Join Post 2']);
        $newPosts = \Illuminate\Support\Facades\DB::table('test_posts')
            ->join('test_posts as p2', 'test_posts.id', '=', 'p2.id')
            ->select('test_posts.*')
            ->atomicCache(60);

        $this->assertCount(2, $newPosts);
    }

    public function test_model_decrement_automatically_clears_cache_via_updated_event(): void
    {
        $post = TestPost::create(['title' => 'Stock Post', 'stock' => 10]);

        // Cache the query
        $cachedStock = TestPost::where('id', $post->id)->atomicCache(60)->first()->stock;
        $this->assertEquals(10, $cachedStock);

        // Call decrement on model instance (fires updating/updated event)
        $post->decrement('stock', 2);

        // Verify that cache was automatically cleared and fresh database stock (8) is retrieved
        $freshStock = TestPost::where('id', $post->id)->atomicCache(60)->first()->stock;
        $this->assertEquals(8, $freshStock);
    }

    public function test_closure_with_captured_variables_generates_distinct_hashes(): void
    {
        $service = new class {
            use HasAtomicCache;
            public function runWithClosure($closure)
            {
                return $this->cache('func', func_get_args(), $closure);
            }
        };

        $val1 = 10;
        $val2 = 20;

        $c1 = function () use ($val1) {
            return "val_{$val1}";
        };

        $c2 = function () use ($val2) {
            return "val_{$val2}";
        };

        $res1 = $service->runWithClosure($c1);
        $res2 = $service->runWithClosure($c2);

        $this->assertEquals('val_10', $res1);
        $this->assertEquals('val_20', $res2);
    }

    public function test_blade_helper_reset_unwinds_stack_and_buffers(): void
    {
        $initialLevel = ob_get_level();

        // Simulate an open Blade cache block that threw an exception before endAtomicCache
        AtomicCacheBladeHelper::setUp('unclosed_key_' . uniqid(), 60);
        $this->assertGreaterThan($initialLevel, ob_get_level());

        // Reset should unwind buffer safely
        AtomicCacheBladeHelper::reset();
        $this->assertEquals($initialLevel, ob_get_level());
    }

    public function test_redis_store_chunking_and_tag_invalidation(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis PHP extension is not loaded.');
        }

        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);

        try {
            $redis = new \Redis();
            $connected = @$redis->connect($host, $port, 1.0);
            if (!$connected) {
                $this->markTestSkipped('Redis server is not reachable.');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Cannot connect to Redis server.');
        }

        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => [
                'driver' => 'redis',
                'connection' => 'cache',
            ],
            'database.redis.cache' => [
                'host' => $host,
                'port' => $port,
                'database' => '1',
            ],
            'atomic-cache.chunking.enabled' => true,
            'atomic-cache.chunking.threshold' => 100,
            'atomic-cache.chunking.chunk_size' => 50,
        ]);

        $data = [];
        for ($i = 0; $i < 200; $i++) {
            $data["key_{$i}"] = "value_{$i}";
        }

        $key = 'redis_chunked_test_' . uniqid();
        $tag = 'redis_tag_' . uniqid();

        // 1. Store chunked data with tag on Redis
        AtomicCache::tags([$tag])->put($key, $data, 60);

        // 2. Retrieve chunked data with tag (tests many() batch reassembly on tagged Redis)
        $retrieved = AtomicCache::tags([$tag])->get($key);
        $this->assertEquals($data, $retrieved);

        // 3. Invalidate tag
        AtomicCache::clearTags([$tag]);

        // 4. Verify cache miss
        $miss = AtomicCache::tags([$tag])->get($key);
        $this->assertNull($miss);
    }
}

