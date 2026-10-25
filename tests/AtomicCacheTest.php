<?php

namespace KaziOmar\AtomicCache\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use KaziOmar\AtomicCache\Facades\AtomicCache;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;
use KaziOmar\AtomicCache\Traits\HasAtomicCache;

class TestPost extends Model
{
    use AutoClearsCache;

    protected $table = 'test_posts';
    protected $guarded = [];
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

        // 4. Flexible remember in service (both orders)
        $r1 = $service->remember('test_srv_r1', 60, fn() => 'r1_val');
        $this->assertEquals('r1_val', $r1);

        $r2 = $service->remember('test_srv_r2', fn() => 'r2_val', 60);
        $this->assertEquals('r2_val', $r2);
    }
}
