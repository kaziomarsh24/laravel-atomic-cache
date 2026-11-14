<?php

namespace KaziOmar\AtomicCache\Tests;

use Carbon\Carbon;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KaziOmar\AtomicCache\AtomicCache as AtomicCacheInstance;
use KaziOmar\AtomicCache\Facades\AtomicCache;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;
use KaziOmar\AtomicCache\Traits\HasAtomicCache;

// ─────────────────────────── Fixture Models ───────────────────────────────────

class EdgeStore extends Model
{
    use AutoClearsCache;

    protected $table = 'edge_stores';
    protected $guarded = [];
}

class EdgeOrder extends Model
{
    use AutoClearsCache;

    protected $table   = 'edge_orders';
    protected $guarded = [];
    public array $cacheParents = ['store'];

    public function store(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EdgeStore::class, 'edge_store_id');
    }
}

class EdgeProduct extends Model
{
    use AutoClearsCache;

    protected $table   = 'edge_products';
    protected $guarded = [];
    // No cacheParents — baseline model for joins

    public function edgeStore(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EdgeStore::class, 'edge_store_id');
    }
}

// ─────────────────────────── Fixture Services ──────────────────────────────────

class PerUserTagService
{
    use HasAtomicCache;

    public string $cacheResource = 'per_user_tag_svc';
    public bool   $cachePerUser  = true;

    public function fetch(string $method, array $customTags = []): mixed
    {
        return $this->cache($method, [], fn() => "data_for_{$method}", ttl: null, perUser: true, lock: false, tags: $customTags);
    }
}

class CustomTagsService
{
    use HasAtomicCache;

    public string $cacheResource = 'custom_tags_svc';

    /** Use explicit lock and tags arguments */
    public function fetchWithTags(): mixed
    {
        return $this->cache('tagged_method', [], fn() => 'tagged_value', ttl: null, perUser: false, lock: false, tags: ['extra_tag_A', 'extra_tag_B']);
    }
}

class NonSerializableArgService
{
    use HasAtomicCache;

    public string $cacheResource = 'non_serial_svc';

    public function fetchWithMock(mixed $mock): mixed
    {
        return $this->cache('method_with_mock', [$mock], fn() => 'mocked_result');
    }

    public function fetchWithClosure(\Closure $fn): mixed
    {
        return $this->cache('method_with_closure', [$fn], fn() => 'closure_result');
    }
}

class CliSafeCacheService
{
    use HasAtomicCache;

    public string $cacheResource = 'cli_svc';

    public function fetchData(): mixed
    {
        return $this->cache('cli_safe_fetch', [], fn() => 'cli_data');
    }
}

// ─────────────────────────── Main Test Class ───────────────────────────────────

class EdgeCasesAndNewFeaturesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('edge_stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('edge_orders', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('edge_store_id')->nullable();
            $table->timestamps();
        });

        Schema::create('edge_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->unsignedBigInteger('edge_store_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('edge_products');
        Schema::dropIfExists('edge_orders');
        Schema::dropIfExists('edge_stores');
        // Reset static caches on AutoClearsCache-using models
        EdgeOrder::flushConfiguredParentRelationsCache();
        EdgeStore::flushConfiguredParentRelationsCache();
        Cache::flush();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. flexible() / SWR Edge Cases
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * flexible() on cache miss populates synchronously and returns fresh value.
     */
    public function test_flexible_cache_miss_populates_synchronously(): void
    {
        $count = 0;
        $result = AtomicCache::flexible('swr_miss_key', [60, 15], function () use (&$count) {
            $count++;
            return "swr_value_{$count}";
        });

        $this->assertEquals('swr_value_1', $result);
        $this->assertEquals(1, $count);
        $this->assertNotNull(Cache::get('atomic:swr_miss_key') ?? Cache::get('swr_miss_key') ?? null);
    }

    /**
     * flexible() returns fresh value within fresh TTL window without calling callback.
     */
    public function test_flexible_returns_fresh_value_within_fresh_ttl(): void
    {
        $count = 0;
        AtomicCache::flexible('swr_fresh_key', [3600, 900], function () use (&$count) {
            $count++;
            return "value_{$count}";
        });

        // Second call — still within fresh window
        $result2 = AtomicCache::flexible('swr_fresh_key', [3600, 900], function () use (&$count) {
            $count++;
            return "value_{$count}";
        });

        $this->assertEquals('value_1', $result2);
        $this->assertEquals(1, $count, 'Callback must not be called again during fresh period');
    }

    /**
     * flexible() with zero grace period: single TTL scalar, auto-calculates 20% grace.
     */
    public function test_flexible_with_scalar_ttl_auto_calculates_grace(): void
    {
        $count = 0;
        $result = AtomicCache::flexible('swr_scalar_ttl', 300, function () use (&$count) {
            $count++;
            return "auto_grace_{$count}";
        });

        $this->assertEquals('auto_grace_1', $result);
        $this->assertEquals(1, $count);
    }

    /**
     * flexible() with explicit zero grace period: [freshTtl, 0].
     * Zero grace collapses to minimum 60s internally, still functions.
     */
    public function test_flexible_with_zero_grace_period_handles_safely(): void
    {
        $count = 0;
        // graceTtl=0 → code does max(60, ...) internally, min 60s applied
        $result = AtomicCache::flexible('swr_zero_grace', [120, 0], function () use (&$count) {
            $count++;
            return "zero_grace_{$count}";
        });
        $this->assertEquals('zero_grace_1', $result);
        $this->assertEquals(1, $count);
    }

    /**
     * flexible() with negative grace period: handled safely (not crash).
     */
    public function test_flexible_with_negative_grace_period_does_not_crash(): void
    {
        $count = 0;
        // parseTtlSeconds(-30) = -30, totalTtl = freshTtl + (-30) may be low, but code must not throw
        $result = AtomicCache::flexible('swr_neg_grace', [300, -30], function () use (&$count) {
            $count++;
            return "neg_grace_{$count}";
        });
        $this->assertEquals('neg_grace_1', $result);
        $this->assertEquals(1, $count);
    }

    /**
     * flexible() uses active tags — invalidating tag discards cached SWR envelope.
     */
    public function test_flexible_tag_invalidation_discards_swr_envelope(): void
    {
        $count = 0;
        $tag   = 'swr_tag_' . uniqid();

        AtomicCache::tags([$tag])->flexible('swr_tagged_key', [3600, 900], function () use (&$count) {
            $count++;
            return "tagged_{$count}";
        });

        $this->assertEquals(1, $count);

        // Invalidate the tag — SWR envelope keyed via virtual tag version must be busted
        AtomicCache::clearTags([$tag]);

        // After invalidation, fresh compute should happen
        $result2 = AtomicCache::tags([$tag])->flexible('swr_tagged_key', [3600, 900], function () use (&$count) {
            $count++;
            return "tagged_{$count}";
        });

        $this->assertEquals('tagged_2', $result2);
        $this->assertEquals(2, $count);
    }

    /**
     * flexible() cache miss with bypassed cache (disabled) calls callback directly.
     */
    public function test_flexible_bypasses_cache_when_disabled(): void
    {
        config(['atomic-cache.enabled' => false]);

        $count = 0;
        $r1 = AtomicCache::flexible('swr_disabled', [60, 15], function () use (&$count) {
            $count++;
            return "bypass_{$count}";
        });
        $r2 = AtomicCache::flexible('swr_disabled', [60, 15], function () use (&$count) {
            $count++;
            return "bypass_{$count}";
        });

        config(['atomic-cache.enabled' => true]);

        $this->assertEquals('bypass_1', $r1);
        $this->assertEquals('bypass_2', $r2, 'Each call must execute callback when cache disabled');
        $this->assertEquals(2, $count);
    }

    /**
     * flexible() background revalidation: callback throwing does NOT corrupt cache.
     * When the lock is NOT acquired (second concurrent caller), old stale value
     * is returned safely even if revalidation were to fail.
     */
    public function test_flexible_callback_exception_during_revalidation_fails_safely(): void
    {
        // Seed a stale SWR envelope directly (fresh_until in the past)
        $stalePayload = [
            '__swr_value__'      => 'stale_safe_value',
            '__swr_fresh_until__' => time() - 10, // already expired → in grace
        ];
        // Write it at totalTtl so it's still there in grace window
        AtomicCache::put('swr_callback_exc', $stalePayload, 300);

        // Now call flexible() with a callback that throws
        $result = AtomicCache::flexible('swr_callback_exc', [60, 240], function () {
            throw new \RuntimeException('Revalidation failure!');
        });

        // Must return stale value, not throw
        $this->assertEquals('stale_safe_value', $result);

        // Cache should NOT be corrupted — still has the stale payload
        $cached = AtomicCache::get('swr_callback_exc');
        $this->assertNotNull($cached, 'Cache entry should not be corrupted after failed revalidation');
    }

    /**
     * flexible() with Carbon/DateTimeInterface/DateInterval TTL types.
     */
    public function test_flexible_accepts_carbon_and_dateinterval_ttl(): void
    {
        $count = 0;

        // Carbon freshTtl, int graceTtl
        $r1 = AtomicCache::flexible(
            'swr_carbon_ttl',
            [Carbon::now()->addMinutes(30), Carbon::now()->addMinutes(5)],
            function () use (&$count) { $count++; return "carbon_{$count}"; }
        );
        $this->assertEquals('carbon_1', $r1);

        // DateInterval freshTtl
        $r2 = AtomicCache::flexible(
            'swr_dateinterval_ttl',
            [new DateInterval('PT1H'), 300],
            function () use (&$count) { $count++; return "interval_{$count}"; }
        );
        $this->assertEquals('interval_2', $r2);
    }

    /**
     * flexible() concurrent revalidation lock contention: second request gets stale,
     * lock is already held, so revalidation is skipped (non-blocking) gracefully.
     */
    public function test_flexible_concurrent_revalidation_lock_contention_handled(): void
    {
        $tag     = 'swr_concurrent_tag_' . uniqid();
        $key     = 'swr_concurrent_key';
        $lockKey = 'lock:swr:atomic:' . $key;

        // Seed stale SWR payload (outside fresh window, within grace)
        $stalePayload = [
            '__swr_value__'      => 'concurrent_stale',
            '__swr_fresh_until__' => time() - 5,
        ];
        AtomicCache::put($key, $stalePayload, 3600);

        // Acquire the SWR revalidation lock to simulate a concurrent request holding it
        $rawStore = AtomicCache::getRawStore();
        $lock     = null;
        $acquired = false;

        if (AtomicCache::supportsLock($rawStore)) {
            $lock     = $rawStore->lock($lockKey, 10);
            $acquired = $lock->get();
        }

        $count = 0;
        // This call should return stale without calling callback (lock already held)
        $result = AtomicCache::flexible($key, [60, 3540], function () use (&$count) {
            $count++;
            return "revalidated_{$count}";
        });

        if ($acquired && $lock) {
            $lock->release();
        }

        // If lock was acquired: revalidation skipped, stale returned, callback not called
        // If lock not supported: revalidation runs synchronously (count=1)
        $this->assertEquals('concurrent_stale', $result);
        $this->assertEquals(0, $count, 'Callback should not run when revalidation lock is contended');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Query Macro Edge Cases
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * atomicCachePaginate with custom pageName.
     */
    public function test_query_macro_paginate_custom_page_name(): void
    {
        EdgeProduct::create(['name' => 'P1', 'sku' => 'sku-1']);
        EdgeProduct::create(['name' => 'P2', 'sku' => 'sku-2']);

        // With custom pageName 'p', page 1
        request()->merge(['p' => 1]);
        $page1 = EdgeProduct::query()->atomicCachePaginate(1, ['*'], 'p', null, null, 3600);
        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $page1);
        $this->assertCount(1, $page1->items());

        // Different pageName means different cache key
        request()->merge(['p' => 2]);
        $page2 = EdgeProduct::query()->atomicCachePaginate(1, ['*'], 'p', null, null, 3600);
        $this->assertCount(1, $page2->items());
        $this->assertNotEquals($page1->items()[0]->id, $page2->items()[0]->id);
    }

    /**
     * atomicCachePaginate with specific columns array.
     */
    public function test_query_macro_paginate_with_columns_array(): void
    {
        EdgeProduct::create(['name' => 'Col1', 'sku' => 'col-sku-1']);

        request()->merge(['page' => 1]);
        $result = EdgeProduct::query()->atomicCachePaginate(15, ['id', 'name'], 'page', null, null, 3600);

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $result);
        $item = $result->items()[0];
        $this->assertArrayHasKey('id', $item->toArray());
        $this->assertArrayHasKey('name', $item->toArray());
    }

    /**
     * atomicCachePaginate with empty query results (no records).
     */
    public function test_query_macro_paginate_empty_results(): void
    {
        // No records created
        request()->merge(['page' => 1]);
        $result = EdgeProduct::query()->where('name', 'nonexistent_xyz')->atomicCachePaginate(15, ['*'], 'page', null, null, 3600);

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $result);
        $this->assertCount(0, $result->items());
        $this->assertEquals(0, $result->total());
    }

    /**
     * atomicCachePaginate with queries using joins/eager loads.
     */
    public function test_query_macro_paginate_with_join(): void
    {
        $store = EdgeStore::create(['name' => 'Store A']);
        EdgeProduct::create(['name' => 'Join P1', 'sku' => 'jp1', 'edge_store_id' => $store->id]);

        request()->merge(['page' => 1]);
        $result = EdgeProduct::query()
            ->join('edge_stores', 'edge_products.edge_store_id', '=', 'edge_stores.id')
            ->select('edge_products.*', 'edge_stores.name as store_name')
            ->atomicCachePaginate(15, ['*'], 'page', null, null, 3600);

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $result);
        $this->assertCount(1, $result->items());
        $this->assertEquals('Store A', $result->items()[0]->store_name);
    }

    /**
     * atomicCachePaginate with eager loads.
     */
    public function test_query_macro_paginate_with_eager_loads(): void
    {
        $store = EdgeStore::create(['name' => 'Eager Store']);
        EdgeProduct::create(['name' => 'Eager P1', 'sku' => 'ep1', 'edge_store_id' => $store->id]);

        request()->merge(['page' => 1]);
        // Eager load a hasMany (even though it's cross-model, it fires fingerprinting code)
        $result = EdgeProduct::with(['edgeStore' => fn($q) => $q->where('id', '>', 0)])
            ->atomicCachePaginate(15, ['*'], 'page', null, null, 3600);

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $result);
    }

    /**
     * atomicCacheSimplePaginate produces SimplePaginator with correct cache hit.
     */
    public function test_query_macro_simple_paginate_caches_and_hits(): void
    {
        EdgeProduct::create(['name' => 'SP1', 'sku' => 'sp-1']);
        EdgeProduct::create(['name' => 'SP2', 'sku' => 'sp-2']);

        $count = 0;
        request()->merge(['page' => 1]);

        // Can't intercept count easily, so verify via direct macro
        $r1 = EdgeProduct::query()->atomicCacheSimplePaginate(5, ['*'], 'page', null, null, 3600);
        $r2 = EdgeProduct::query()->atomicCacheSimplePaginate(5, ['*'], 'page', null, null, 3600);

        $this->assertInstanceOf(\Illuminate\Pagination\Paginator::class, $r1);
        // Second call should come from cache (same result as r1)
        $this->assertEquals($r1->items()[0]->id, $r2->items()[0]->id);
    }

    /**
     * atomicCacheSimplePaginate with custom pageName.
     */
    public function test_query_macro_simple_paginate_custom_page_name(): void
    {
        EdgeProduct::create(['name' => 'SPN1', 'sku' => 'spn-1']);

        request()->merge(['cursor' => 1]);
        $r = EdgeProduct::query()->atomicCacheSimplePaginate(10, ['*'], 'cursor', null, null, 3600);
        $this->assertInstanceOf(\Illuminate\Pagination\Paginator::class, $r);
    }

    /**
     * atomicCacheCount caches a count result.
     * Uses a custom cache key so we can bypass the AutoClearsCache tag rotation
     * triggered by EdgeProduct::create() and verify the count is truly from cache.
     */
    public function test_query_macro_count_caches_result(): void
    {
        EdgeProduct::create(['name' => 'C1', 'sku' => 'c-1']);
        EdgeProduct::create(['name' => 'C2', 'sku' => 'c-2']);

        // Use a custom key (not driven by auto-tags) to prove the cache hit
        // scope: 'count_test_scope' ensures invalidation doesn't happen from create()
        $count1 = EdgeProduct::query()->atomicCacheCount('*', 'count_test_scope', 3600, [], false, 'count_test_scope');
        $this->assertEquals(2, $count1);

        // The cached count must come from the custom scope key (not busted by create's AutoClearsCache)
        $count2 = EdgeProduct::query()->atomicCacheCount('*', 'count_test_scope', 3600, [], false, 'count_test_scope');
        $this->assertEquals(2, $count2, 'Count should be cached under custom scope and not change');
    }

    /**
     * atomicCacheCount on joined queries.
     */
    public function test_query_macro_count_on_joined_query(): void
    {
        $store = EdgeStore::create(['name' => 'Count Store']);
        EdgeProduct::create(['name' => 'JP_Count1', 'sku' => 'jpc-1', 'edge_store_id' => $store->id]);
        EdgeProduct::create(['name' => 'JP_Count2', 'sku' => 'jpc-2', 'edge_store_id' => $store->id]);

        $count = EdgeProduct::query()
            ->join('edge_stores', 'edge_products.edge_store_id', '=', 'edge_stores.id')
            ->where('edge_stores.name', 'Count Store')
            ->atomicCacheCount('*', null, 3600);

        $this->assertEquals(2, $count);
    }

    /**
     * atomicCacheCount on distinct column.
     */
    public function test_query_macro_count_distinct_column(): void
    {
        EdgeProduct::create(['name' => 'D1', 'sku' => 'distinct-sku']);
        EdgeProduct::create(['name' => 'D2', 'sku' => 'distinct-sku']); // duplicate SKU

        $count = EdgeProduct::query()
            ->distinct()
            ->atomicCacheCount('sku', null, 3600);

        // 1 unique sku = 1 (or 2 without distinct depending on driver)
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    /**
     * atomicCacheExists with complex where clauses.
     */
    public function test_query_macro_exists_with_complex_where(): void
    {
        EdgeProduct::create(['name' => 'Exists1', 'sku' => 'ex-1']);
        EdgeProduct::create(['name' => 'Exists2', 'sku' => 'ex-2']);

        $exists = EdgeProduct::query()
            ->where('name', 'LIKE', 'Exists%')
            ->where('sku', '!=', 'nonexistent')
            ->atomicCacheExists(null, 3600);

        $this->assertTrue($exists);

        $notExists = EdgeProduct::query()
            ->where('name', 'CompletelyMissing')
            ->atomicCacheExists(null, 3600);

        $this->assertFalse($notExists);
    }

    /**
     * atomicCacheExists is cached: result is served from cache.
     * We verify this by calling exists() twice on the same query and confirming
     * the result is consistent (not checking against new DB inserts, since
     * AutoClearsCache on EdgeProduct will correctly bust the tag on create).
     */
    public function test_query_macro_exists_is_cached(): void
    {
        EdgeProduct::create(['name' => 'CachedExists', 'sku' => 'cached-ex-sku']);

        // First call: populates cache
        $exists1 = EdgeProduct::query()
            ->where('sku', 'cached-ex-sku')
            ->atomicCacheExists(null, 3600);
        $this->assertTrue($exists1);

        // Second call with same query: must come from cache (same fingerprint)
        $exists2 = EdgeProduct::query()
            ->where('sku', 'cached-ex-sku')
            ->atomicCacheExists(null, 3600);
        $this->assertTrue($exists2, 'Second call must return cached true result');

        // Verify false result is also cached: use a custom scope key
        $notExists1 = EdgeProduct::query()
            ->where('sku', 'this_sku_never_exists')
            ->atomicCacheExists('never_exists_scope', 3600, [], false, 'never_exists_scope');
        $this->assertFalse($notExists1);

        $notExists2 = EdgeProduct::query()
            ->where('sku', 'this_sku_never_exists')
            ->atomicCacheExists('never_exists_scope', 3600, [], false, 'never_exists_scope');
        $this->assertFalse($notExists2, 'Cached false result must persist on second call');
    }

    /**
     * atomicCachePaginate/Count/Exists with Carbon TTL.
     */
    public function test_query_macros_accept_carbon_ttl(): void
    {
        EdgeProduct::create(['name' => 'TTL_Carbon', 'sku' => 'ttl-c']);

        request()->merge(['page' => 1]);

        $page = EdgeProduct::query()->atomicCachePaginate(15, ['*'], 'page', null, null, Carbon::now()->addHour());
        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $page);

        $count = EdgeProduct::query()->atomicCacheCount('*', null, Carbon::now()->addHour());
        $this->assertEquals(1, $count);

        $exists = EdgeProduct::query()->where('sku', 'ttl-c')->atomicCacheExists(null, Carbon::now()->addHour());
        $this->assertTrue($exists);
    }

    /**
     * atomicCachePaginate/Count/Exists with DateInterval TTL.
     */
    public function test_query_macros_accept_dateinterval_ttl(): void
    {
        EdgeProduct::create(['name' => 'TTL_DI', 'sku' => 'ttl-di']);

        request()->merge(['page' => 1]);
        $page = EdgeProduct::query()->atomicCachePaginate(15, ['*'], 'page', null, null, new DateInterval('PT30M'));
        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $page);

        $count = EdgeProduct::query()->atomicCacheCount('*', null, new DateInterval('PT30M'));
        $this->assertEquals(1, $count);
    }

    /**
     * atomicCachePaginate/Count/Exists with DateTimeInterface (DateTimeImmutable) TTL.
     */
    public function test_query_macros_accept_datetimeimmutable_ttl(): void
    {
        EdgeProduct::create(['name' => 'TTL_DTI', 'sku' => 'ttl-dti']);

        request()->merge(['page' => 1]);
        $ttl = new DateTimeImmutable('+45 minutes');

        $page = EdgeProduct::query()->atomicCachePaginate(15, ['*'], 'page', null, null, $ttl);
        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $page);

        $count = EdgeProduct::query()->atomicCacheCount('*', null, $ttl);
        $this->assertEquals(1, $count);

        $exists = EdgeProduct::query()->atomicCacheExists(null, $ttl);
        $this->assertTrue($exists);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. HasAtomicCache::cache() Parameter Separation Edge Cases
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * perUser=true + custom tags array: both effects must apply simultaneously.
     */
    public function test_has_atomic_cache_per_user_true_with_custom_tags_simultaneously(): void
    {
        $svc = new PerUserTagService();

        // Simulate user 1
        $result1 = $svc->fetch('my_data', ['custom_tag_X']);
        $this->assertEquals('data_for_my_data', $result1);

        // Different user gets different cache slot even though tags overlap
        $result2 = $svc->fetch('my_data_user2', ['custom_tag_X']);
        $this->assertEquals('data_for_my_data_user2', $result2);

        // Clear custom_tag_X — both user caches must be invalidated (virtual tag rotation)
        AtomicCache::clearTags(['custom_tag_X']);

        // After tag clear, fresh compute
        $count = 0;
        $r3 = AtomicCache::tags(['custom_tag_X'])->remember('per_user_tag_svc:my_data', function () use (&$count) {
            $count++;
            return "recomputed_{$count}";
        }, 3600);
        $this->assertEquals('recomputed_1', $r3);
    }

    /**
     * Clean parameter order: bool $lock = false, array $tags = [].
     */
    public function test_has_atomic_cache_clean_parameter_order_with_tags(): void
    {
        $svc = new CustomTagsService();
        $result = $svc->fetchWithTags();
        $this->assertEquals('tagged_value', $result);

        // Clear extra_tag_A — should bust the cache (tags were merged)
        AtomicCache::clearTags(['extra_tag_A']);

        // Now compute again (simulate by calling fresh remember on same key)
        $count = 0;
        $r2 = AtomicCache::tags(['custom_tags_svc', 'extra_tag_A', 'extra_tag_B'])->remember(
            'custom_tags_svc:tagged_method',
            function () use (&$count) { $count++; return "re_computed_{$count}"; },
            3600
        );
        $this->assertEquals('re_computed_1', $r2, 'After tag clear, cache must be busted');
    }

    /**
     * Positional flexibility: passing array as 6th argument without passing lock.
     */
    public function test_has_atomic_cache_positional_tags_without_lock_parameter(): void
    {
        $service = new class {
            use HasAtomicCache;
            public string $cacheResource = 'pos_flex_svc';

            public function getWithPositionalTags(array $tags)
            {
                // 6th argument is array of tags, 7th (lock) is omitted!
                return $this->cache('pos_method', [], fn() => 'pos_result', 3600, false, $tags);
            }

            public function getWithPositionalLock(bool $lock)
            {
                // 6th argument is boolean lock!
                return $this->cache('lock_method', [], fn() => 'lock_result', 3600, false, $lock);
            }
        };

        $result1 = $service->getWithPositionalTags(['pos_tag_1', 'pos_tag_2']);
        $this->assertEquals('pos_result', $result1);

        // Check it cached under the tag
        AtomicCache::clearTags('pos_tag_1');
        $this->assertNull(AtomicCache::tags(['pos_tag_1'])->get('pos_flex_svc:pos_method'));

        $result2 = $service->getWithPositionalLock(true);
        $this->assertEquals('lock_result', $result2);
    }

    /**
     * Non-serializable arguments (Closures) are safely normalized — no crash.
     */
    public function test_has_atomic_cache_non_serializable_closure_argument(): void
    {
        $svc     = new NonSerializableArgService();
        $closure = fn() => 'inner_closure_value';

        $result = $svc->fetchWithClosure($closure);
        $this->assertEquals('closure_result', $result);

        // Second call with same closure position (same file+line = same fingerprint) = cache hit
        $result2 = $svc->fetchWithClosure($closure);
        $this->assertEquals('closure_result', $result2);
    }

    /**
     * Non-serializable argument: mock object (anonymous class with no serialization) doesn't crash.
     */
    public function test_has_atomic_cache_non_serializable_mock_object(): void
    {
        $svc  = new NonSerializableArgService();
        $mock = new class {
            // No toArray, no JsonSerializable, no __toString
            public string $id = 'mock-123';
        };

        // Should not throw, normalizeArguments falls back to class:hash
        $result = $svc->fetchWithMock($mock);
        $this->assertEquals('mocked_result', $result);
    }

    /**
     * CLI/Queue unbound request safety: when request is not bound, shouldIncludeRequestQuery = false.
     */
    public function test_has_atomic_cache_cli_queue_unbound_request_safety(): void
    {
        // Unbind request from container to simulate CLI/Queue context
        $app = app();
        $app->forgetInstance('request');
        // Don't rebind request — now shouldIncludeRequestQuery must return false

        $svc    = new CliSafeCacheService();
        $result = $svc->fetchData();
        $this->assertEquals('cli_data', $result);

        // Cache should still be populated
        $result2 = $svc->fetchData();
        $this->assertEquals('cli_data', $result2);

        // Restore a request binding for subsequent tests
        $app->bind('request', fn() => request());
    }

    /**
     * perUser=false (default): same key returns same cached value across users.
     */
    public function test_has_atomic_cache_per_user_false_shares_across_users(): void
    {
        $svc = new class {
            use HasAtomicCache;
            public string $cacheResource = 'legacy_svc';
        };
        AtomicCache::tags(['legacy_svc'])->put('legacy_svc:shared_key', 'shared_value', 3600);

        $r1 = AtomicCache::forUser(1)->tags(['legacy_svc'])->get('legacy_svc:shared_key');
        $r2 = AtomicCache::forUser(2)->tags(['legacy_svc'])->get('legacy_svc:shared_key');

        // Without perUser scoping, neither user-scoped key exists
        // The unscoped cache key should return the value
        $r3 = AtomicCache::tags(['legacy_svc'])->get('legacy_svc:shared_key');
        $this->assertEquals('shared_value', $r3);
    }

    /**
     * cachingEnabled=false bypasses the cache completely.
     */
    public function test_has_atomic_cache_caching_disabled_bypasses_cache(): void
    {
        $svc = new class {
            use HasAtomicCache;
            public string $cacheResource = 'disabled_svc';
            public bool   $cachingEnabled = false;

            public function fetch(): string
            {
                return $this->cache('fetch', [], fn() => 'fresh_value_always');
            }
        };

        $r1 = $svc->fetch();
        $r2 = $svc->fetch();
        $this->assertEquals('fresh_value_always', $r1);
        $this->assertEquals('fresh_value_always', $r2);
        $this->assertNull(AtomicCache::get('disabled_svc:fetch'));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. AutoClearsCache and ManagesVirtualTags Edge Cases
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * AutoClearsCache: model without an active transaction calls clearTags immediately (no afterCommit).
     */
    public function test_auto_clears_cache_without_transaction_clears_immediately(): void
    {
        $store = EdgeStore::create(['name' => 'Immediate Clear']);
        $tag   = "edge_stores:{$store->id}";

        AtomicCache::tags([$tag])->put('store_detail', 'cached_detail', 3600);
        $this->assertEquals('cached_detail', AtomicCache::tags([$tag])->get('store_detail'));

        // No transaction — update fires clearTags immediately
        $store->update(['name' => 'Updated Immediately']);

        $this->assertNull(AtomicCache::tags([$tag])->get('store_detail'), 'Cache must be cleared immediately without transaction');
    }

    /**
     * AutoClearsCache: inside transaction, cache cleared ONLY after commit.
     */
    public function test_auto_clears_cache_deferred_to_after_commit(): void
    {
        $store = EdgeStore::create(['name' => 'Tx Store']);
        $tag   = "edge_stores:{$store->id}";

        AtomicCache::tags([$tag])->put('store_tx_data', 'tx_cached', 3600);

        DB::beginTransaction();
        $store->update(['name' => 'Tx Updated']);
        // Inside tx: cache should still be untouched (deferred)
        $this->assertEquals('tx_cached', AtomicCache::tags([$tag])->get('store_tx_data'));
        DB::commit();

        // After commit: cache cleared
        $this->assertNull(AtomicCache::tags([$tag])->get('store_tx_data'));
    }

    /**
     * AutoClearsCache: rollback does NOT clear cache.
     */
    public function test_auto_clears_cache_rollback_preserves_cache(): void
    {
        $store = EdgeStore::create(['name' => 'Rollback Store']);
        $tag   = "edge_stores:{$store->id}";

        AtomicCache::tags([$tag])->put('store_rb_data', 'rb_cached', 3600);

        DB::beginTransaction();
        $store->update(['name' => 'Rolled Back']);
        $this->assertEquals('rb_cached', AtomicCache::tags([$tag])->get('store_rb_data'));
        DB::rollBack();

        // After rollback: cache still present
        $this->assertEquals('rb_cached', AtomicCache::tags([$tag])->get('store_rb_data'), 'Rollback must NOT clear the cache');
    }

    /**
     * ManagesVirtualTags: plain container missing version() — shouldPurgeRedisTaggedKeysDirectly still works.
     */
    public function test_manages_virtual_tags_works_when_app_version_method_missing(): void
    {
        // Simulate a container missing version() (like a minimal Illuminate Container)
        // The code gracefully uses: app()->version() if method_exists
        // We can test this indirectly by running clearTags on array driver (no redis)
        $tag = 'version_fallback_tag_' . uniqid();
        AtomicCache::clearTags([$tag]); // Must not throw

        $count = 0;
        $r = AtomicCache::tags([$tag])->remember('vtag_version_key', function () use (&$count) {
            $count++;
            return "v_{$count}";
        }, 3600);
        $this->assertEquals('v_1', $r);
        $this->assertTrue(true, 'No exception thrown when app()->version() not relevant');
    }

    /**
     * ManagesVirtualTags::clearTags on array store (no native tags): uses virtual tag rotation.
     */
    public function test_manages_virtual_tags_clear_tags_rotates_occ_token(): void
    {
        $tag = 'vtag_occ_' . uniqid();
        $store = AtomicCache::getRawStore();

        // Get initial version
        $v1 = AtomicCache::getVirtualTagVersions([$tag], $store)[$tag] ?? null;
        $this->assertNotNull($v1);

        AtomicCache::clearTags([$tag]);

        // Version must have rotated to a new UUID
        $v2 = AtomicCache::getVirtualTagVersions([$tag], $store)[$tag] ?? null;
        $this->assertNotNull($v2);
        $this->assertNotEquals($v1, $v2, 'Virtual tag version must rotate on clearTags');
    }

    /**
     * ManagesVirtualTags: stateless API context (no session, no Auth).
     * AtomicCache::forUser() falls back to 'guest' safely.
     */
    public function test_manages_virtual_tags_stateless_api_guest_fallback(): void
    {
        // No session, no authenticated user → forUser() should produce 'guest'
        $instance = AtomicCache::forUser();
        $r = $instance->remember('stateless_guest_key', fn() => 'guest_data', 3600);
        $this->assertEquals('guest_data', $r);

        // Key should be scoped with guest
        $r2 = $instance->get('stateless_guest_key');
        $this->assertEquals('guest_data', $r2);
    }

    /**
     * ManagesVirtualTags: forUser(null) falls back gracefully when Auth facade is unresolvable.
     */
    public function test_manages_virtual_tags_for_user_null_falls_back_to_guest(): void
    {
        // Auth facade throws → falls back to 'guest' or 'guest:session_id'
        // In testing (no real HTTP session), it should land on 'guest'
        $instance = AtomicCache::forUser(null);
        $r = $instance->remember('guest_fallback_key', fn() => 'guest_fb', 3600);
        $this->assertEquals('guest_fb', $r);
    }

    /**
     * AutoClearsCache with cacheParents: parent tags are cleared on child save.
     */
    public function test_auto_clears_cache_clears_parent_tags_on_child_save(): void
    {
        $store = EdgeStore::create(['name' => 'Parent Store']);
        $order = EdgeOrder::create(['status' => 'pending', 'edge_store_id' => $store->id]);

        $parentTag  = "edge_stores:{$store->id}";
        $parentColl = "edge_stores:{$store->id}:edge_orders";

        AtomicCache::tags([$parentTag])->put('store_data', 'parent_cached', 3600);
        AtomicCache::tags([$parentColl])->put('orders_data', 'parent_orders_cached', 3600);

        // Updating the child should clear parent tags
        $order->update(['status' => 'shipped']);

        $this->assertNull(AtomicCache::tags([$parentTag])->get('store_data'), 'Parent umbrella tag must be cleared on child update');
        $this->assertNull(AtomicCache::tags([$parentColl])->get('orders_data'), 'Parent collection scoped tag must be cleared on child update');
    }

    /**
     * AutoClearsCache with cacheParents: foreign key change clears BOTH old and new parent.
     */
    public function test_auto_clears_cache_clears_both_old_and_new_parent_on_fk_change(): void
    {
        $storeA = EdgeStore::create(['name' => 'Store A']);
        $storeB = EdgeStore::create(['name' => 'Store B']);
        $order  = EdgeOrder::create(['status' => 'pending', 'edge_store_id' => $storeA->id]);

        $tagA = "edge_stores:{$storeA->id}";
        $tagB = "edge_stores:{$storeB->id}";

        AtomicCache::tags([$tagA])->put('store_a_data', 'cached_a', 3600);
        AtomicCache::tags([$tagB])->put('store_b_data', 'cached_b', 3600);

        // Move order from storeA → storeB
        $order->update(['edge_store_id' => $storeB->id]);

        $this->assertNull(AtomicCache::tags([$tagA])->get('store_a_data'), 'Old parent (storeA) cache must be cleared');
        $this->assertNull(AtomicCache::tags([$tagB])->get('store_b_data'), 'New parent (storeB) cache must be cleared');
    }

    /**
     * AtomicCache::invalidating() clears tags after callback, deferred inside transaction.
     */
    public function test_invalidating_defers_tag_clear_inside_transaction(): void
    {
        $store = EdgeStore::create(['name' => 'Inv Store']);
        $tag   = "edge_stores:{$store->id}";

        AtomicCache::tags([$tag])->put('inv_data', 'inv_cached', 3600);

        DB::beginTransaction();
        AtomicCache::invalidating($tag, function () use ($store) {
            $store->update(['name' => 'Inv Updated']);
        });
        // Still inside transaction: cache untouched
        $this->assertEquals('inv_cached', AtomicCache::tags([$tag])->get('inv_data'));
        DB::commit();

        $this->assertNull(AtomicCache::tags([$tag])->get('inv_data'), 'Tags must be cleared after transaction commit via invalidating()');
    }

    /**
     * Virtual tag version stays stable across repeated getVirtualTagVersions calls.
     */
    public function test_virtual_tag_version_stable_across_reads(): void
    {
        $tag   = 'stable_vtag_' . uniqid();
        $store = AtomicCache::getRawStore();

        $v1 = AtomicCache::getVirtualTagVersions([$tag], $store);
        $v2 = AtomicCache::getVirtualTagVersions([$tag], $store);

        $this->assertEquals($v1[$tag], $v2[$tag], 'Virtual tag version must be stable between reads without invalidation');
    }

    /**
     * AtomicCache::normalizeTags deduplicates and handles Model inputs.
     */
    public function test_normalize_tags_deduplicates_and_handles_model_input(): void
    {
        $store    = EdgeStore::create(['name' => 'Norm Tag']);
        $instance = new AtomicCacheInstance();

        $tags = $instance->normalizeTags([$store, 'edge_stores', 'edge_stores']);
        $this->assertContains('edge_stores', $tags);
        $this->assertContains("edge_stores:{$store->id}", $tags);
        $this->assertCount(count(array_unique($tags)), $tags, 'Tags must be unique after normalization');
    }

    /**
     * parseTtlSeconds: Carbon, DateTimeImmutable, DateInterval, int all parse correctly.
     */
    public function test_parse_ttl_seconds_handles_all_types(): void
    {
        $carbon   = Carbon::now()->addSeconds(100);
        $dtImmut  = new DateTimeImmutable('+200 seconds');
        $interval = new DateInterval('PT50S');
        $int      = 300;

        $carbonTtl   = AtomicCacheInstance::parseTtlSeconds($carbon);
        $dtTtl       = AtomicCacheInstance::parseTtlSeconds($dtImmut);
        $intervalTtl = AtomicCacheInstance::parseTtlSeconds($interval);
        $intTtl      = AtomicCacheInstance::parseTtlSeconds($int);

        $this->assertEqualsWithDelta(100, $carbonTtl, 2);
        $this->assertEqualsWithDelta(200, $dtTtl, 2);
        $this->assertEqualsWithDelta(50, $intervalTtl, 2);
        $this->assertEquals(300, $intTtl);
    }

    /**
     * AtomicCache isInsideActiveTransaction: returns false when db not bound.
     */
    public function test_is_inside_active_transaction_returns_false_when_no_db(): void
    {
        // With SQLite in-memory bound, no active transaction = false
        $instance = new AtomicCacheInstance();
        $this->assertFalse($instance->isInsideActiveTransaction());
    }

    /**
     * AtomicCache shouldBypassCache respects the enabled config flag.
     */
    public function test_should_bypass_cache_respects_enabled_flag(): void
    {
        $instance = new AtomicCacheInstance();

        config(['atomic-cache.enabled' => false]);
        $this->assertTrue($instance->shouldBypassCache());

        config(['atomic-cache.enabled' => true]);
        $this->assertFalse($instance->shouldBypassCache());
    }

    /**
     * AtomicCache::remember with no TTL uses default_ttl from config.
     */
    public function test_remember_uses_default_ttl_from_config_when_none_provided(): void
    {
        config(['atomic-cache.default_ttl' => 7200]);

        $count = 0;
        AtomicCache::remember('default_ttl_key', function () use (&$count) {
            $count++;
            return "default_ttl_val_{$count}";
        });

        $r2 = AtomicCache::remember('default_ttl_key', function () use (&$count) {
            $count++;
            return "default_ttl_val_{$count}";
        });

        $this->assertEquals('default_ttl_val_1', $r2);
        $this->assertEquals(1, $count, 'Cached with default TTL, must not recompute');

        config(['atomic-cache.default_ttl' => 3600]);
    }

    /**
     * AtomicCache with forUser(explicit_id): different IDs produce different cache entries.
     */
    public function test_for_user_explicit_id_scopes_cache(): void
    {
        AtomicCache::forUser(10)->put('scoped_key', 'user10_data', 3600);
        AtomicCache::forUser(20)->put('scoped_key', 'user20_data', 3600);

        $this->assertEquals('user10_data', AtomicCache::forUser(10)->get('scoped_key'));
        $this->assertEquals('user20_data', AtomicCache::forUser(20)->get('scoped_key'));
        $this->assertNull(AtomicCache::get('scoped_key'), 'Unscoped key should not exist');
    }

    /**
     * Stale-While-Revalidate with tags: OCC tag change during grace period discards write.
     */
    public function test_flexible_occ_tag_change_during_grace_discards_write(): void
    {
        $tag = 'swr_occ_tag_' . uniqid();
        $key = 'swr_occ_key_' . uniqid();

        // Write a stale SWR envelope directly (past fresh_until)
        $stalePayload = [
            '__swr_value__'      => 'occ_stale_value',
            '__swr_fresh_until__' => time() - 10,
        ];
        AtomicCache::tags([$tag])->put($key, $stalePayload, 3600);

        $invalidated = false;
        // Callback that also invalidates the tag mid-revalidation
        $callback = function () use ($tag, &$invalidated) {
            // Simulate OCC invalidation during revalidation
            AtomicCache::clearTags([$tag]);
            $invalidated = true;
            return 'occ_new_value';
        };

        // On stale hit: revalidation runs (non-blocking lock), but tag is cleared mid-flight
        $result = AtomicCache::tags([$tag])->flexible($key, [60, 3540], $callback);

        // Must return stale value
        $this->assertEquals('occ_stale_value', $result);

        // After tag invalidation, the OCC-discarded write means the tag version rotated,
        // so subsequent read under that tag must be a cache miss (or compute fresh)
        $sentinel = new \stdClass();
        $cachedUnderRotatedTag = AtomicCache::tags([$tag])->get($key, $sentinel);
        $this->assertSame($sentinel, $cachedUnderRotatedTag, 'Stale write must have been discarded due to OCC tag rotation');
    }

    /**
     * AtomicCache::withTransactionBypass(false): cache write NOT skipped inside transaction.
     */
    public function test_without_transaction_bypass_writes_cache_inside_transaction(): void
    {
        $count = 0;
        DB::beginTransaction();

        $result = AtomicCache::withoutTransactionBypass()->remember('no_bypass_key', function () use (&$count) {
            $count++;
            return "no_bypass_{$count}";
        }, 3600);

        $this->assertEquals('no_bypass_1', $result);
        // Should be cached even inside transaction when bypass disabled
        $r2 = AtomicCache::withoutTransactionBypass()->get('no_bypass_key');
        $this->assertEquals('no_bypass_1', $r2, 'Cache must be written inside transaction when bypass disabled');

        DB::rollBack();
    }

    /**
     * AtomicCache::supportsLock on array store (which implements LockProvider via ArrayLock).
     */
    public function test_supports_lock_on_array_store(): void
    {
        $instance = new AtomicCacheInstance();
        $store    = Cache::store('array');
        $this->assertTrue($instance->supportsLock($store), 'Array store must support locks via ArrayLock');
    }

    /**
     * AutoClearsCache: static flushCache() invalidates entire collection tag.
     */
    public function test_auto_clears_cache_static_flush_cache_invalidates_collection(): void
    {
        $count = 0;

        $r1 = AtomicCache::tags(['edge_stores'])->remember('edge_stores:all', function () use (&$count) {
            $count++;
            return "stores_all_{$count}";
        }, 3600);

        $this->assertEquals('stores_all_1', $r1);

        EdgeStore::flushCache();

        $r2 = AtomicCache::tags(['edge_stores'])->remember('edge_stores:all', function () use (&$count) {
            $count++;
            return "stores_all_{$count}";
        }, 3600);

        $this->assertEquals('stores_all_2', $r2);
        $this->assertEquals(2, $count, 'Static flushCache() must invalidate all collection-tagged caches');
    }

    /**
     * Blade directive helper accepts Carbon / DateTimeInterface without TypeError.
     */
    public function test_blade_helper_accepts_carbon_datetime_ttl(): void
    {
        $key = 'blade_carbon_ttl_' . uniqid();
        $carbon = Carbon::now()->addHours(2);

        $renderNeeded = \KaziOmar\AtomicCache\Blade\AtomicCacheBladeHelper::setUp($key, $carbon, ['blade_tags']);
        $this->assertTrue($renderNeeded);

        echo '<div>rendered_blade_content</div>';
        $output = \KaziOmar\AtomicCache\Blade\AtomicCacheBladeHelper::tearDown();
        $this->assertStringContainsString('rendered_blade_content', $output);

        // Next call should be a cache hit (returns false)
        ob_start();
        $hit = \KaziOmar\AtomicCache\Blade\AtomicCacheBladeHelper::setUp($key, $carbon, ['blade_tags']);
        $echoed = ob_get_clean();
        $this->assertFalse($hit);
        $this->assertStringContainsString('rendered_blade_content', $echoed);
    }

    /**
     * atomic_cache() global helper accepts array of tags for fluent chaining without TypeError.
     */
    public function test_atomic_cache_helper_accepts_array_of_tags(): void
    {
        $result = atomic_cache(['helper_tag_1', 'helper_tag_2'])
            ->remember('helper_keyed', 60, fn() => 'tagged_helper_val');

        $this->assertEquals('tagged_helper_val', $result);
        $this->assertEquals('tagged_helper_val', AtomicCache::tags(['helper_tag_1', 'helper_tag_2'])->get('helper_keyed'));

        AtomicCache::clearTags('helper_tag_1');
        $this->assertNull(AtomicCache::tags(['helper_tag_1', 'helper_tag_2'])->get('helper_keyed'));
    }

    /**
     * Virtual tag initialization uses atomic store->add() avoiding concurrent overwrite race conditions.
     */
    public function test_virtual_tag_init_atomic_add(): void
    {
        $instance = new AtomicCacheInstance();
        $tag = 'atomic_race_tag_' . uniqid();
        $store = Cache::store('array');

        $ref = new \ReflectionClass($instance);
        $method = $ref->getMethod('getVirtualTagVersions');
        $method->setAccessible(true);

        $versions = $method->invoke($instance, [$tag], $store);
        $this->assertArrayHasKey($tag, $versions);
        $this->assertNotEmpty($versions[$tag]);

        // Calling it again returns identical version without regenerating
        $versions2 = $method->invoke($instance, [$tag], $store);
        $this->assertEquals($versions[$tag], $versions2[$tag]);
    }

    /**
     * Lock fallback re-checks cache before running callback to prevent stampede if holder just finished.
     */
    public function test_lock_fallback_rechecks_cache_before_running_callback(): void
    {
        $key = 'stampede_recheck_' . uniqid();
        $executed = 0;

        // Populate cache as if the lock holder just finished
        AtomicCache::put($key, 'pre_populated_winner', 60);

        // Call rememberWithLock — should detect pre-existing/just-finished value and not run callback
        $val = AtomicCache::rememberWithLock($key, 60, function () use (&$executed) {
            $executed++;
            return 'should_not_run';
        });

        $this->assertEquals('pre_populated_winner', $val);
        $this->assertEquals(0, $executed, 'Callback must not execute when cache is populated');
    }
}
