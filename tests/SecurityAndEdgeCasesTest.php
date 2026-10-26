<?php

namespace KaziOmar\AtomicCache\Tests;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use KaziOmar\AtomicCache\AtomicCache as AtomicCacheInstance;
use KaziOmar\AtomicCache\Facades\AtomicCache;
use KaziOmar\AtomicCache\Traits\HasAtomicCache;

class TestSecurityService
{
    use HasAtomicCache;

    public int $callCount = 0;

    public function fetchWithQuery()
    {
        return $this->cache('security_query_test', func_get_args(), function () {
            $this->callCount++;
            return 'computed_' . $this->callCount;
        });
    }

    public function doRemember(string $key, mixed ...$args)
    {
        return $this->remember($key, ...$args);
    }
}

class SecurityAndEdgeCasesTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Test 1: AtomicCache::remember supports named arguments across all permutations.
     */
    public function test_atomic_cache_remember_supports_named_arguments_in_all_permutations(): void
    {
        // 1. remember($key, 3600, callback: fn() => ...)
        $val1 = AtomicCache::remember('named_test_1', 3600, callback: fn() => 'result_1');
        $this->assertEquals('result_1', $val1);
        $this->assertEquals('result_1', AtomicCache::get('named_test_1'));

        // 2. remember($key, callback: fn() => ..., ttl: 3600)
        $val2 = AtomicCache::remember('named_test_2', callback: fn() => 'result_2', ttl: 3600);
        $this->assertEquals('result_2', $val2);
        $this->assertEquals('result_2', AtomicCache::get('named_test_2'));

        // 3. remember($key, ttl: 3600, callback: fn() => ...)
        $val3 = AtomicCache::remember('named_test_3', ttl: 3600, callback: fn() => 'result_3');
        $this->assertEquals('result_3', $val3);
        $this->assertEquals('result_3', AtomicCache::get('named_test_3'));

        // 4. remember($key, fn() => ..., 3600) [inverted positional]
        $val4 = AtomicCache::remember('named_test_4', fn() => 'result_4', 3600);
        $this->assertEquals('result_4', $val4);
        $this->assertEquals('result_4', AtomicCache::get('named_test_4'));

        // 5. remember($key, 3600, fn() => ...) [standard positional]
        $val5 = AtomicCache::remember('named_test_5', 3600, fn() => 'result_5');
        $this->assertEquals('result_5', $val5);
        $this->assertEquals('result_5', AtomicCache::get('named_test_5'));
    }

    /**
     * Test 2: AtomicCache::remember throws InvalidArgumentException when Closure is missing.
     */
    public function test_atomic_cache_remember_throws_when_closure_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[AtomicCache] remember requires a Closure callback.');

        AtomicCache::remember('missing_closure_key', 3600);
    }

    /**
     * Test 3: HasAtomicCache trait supports named arguments and diverse TTL types (DateTime, DateInterval).
     */
    public function test_has_atomic_cache_trait_supports_named_arguments_and_various_ttl_types(): void
    {
        $service = new TestSecurityService();

        // 1. Named argument with callback:
        $res1 = $service->doRemember('trait_named_1', 3600, callback: fn() => 'trait_val_1');
        $this->assertEquals('trait_val_1', $res1);
        $hit1 = $service->doRemember('trait_named_1', 3600, callback: fn() => 'never_called');
        $this->assertEquals('trait_val_1', $hit1);

        // 2. Named arguments: callback & ttl
        $res2 = $service->doRemember('trait_named_2', callback: fn() => 'trait_val_2', ttl: 1800);
        $this->assertEquals('trait_val_2', $res2);
        $hit2 = $service->doRemember('trait_named_2', callback: fn() => 'never_called', ttl: 1800);
        $this->assertEquals('trait_val_2', $hit2);

        // 3. Carbon / DateTimeInterface TTL
        $carbonTtl = Carbon::now()->addHours(2);
        $res3 = $service->doRemember('trait_ttl_carbon', $carbonTtl, fn() => 'carbon_val');
        $this->assertEquals('carbon_val', $res3);
        $hit3 = $service->doRemember('trait_ttl_carbon', $carbonTtl, fn() => 'never_called');
        $this->assertEquals('carbon_val', $hit3);

        // 4. DateInterval TTL
        $interval = new DateInterval('PT1H');
        $res4 = $service->doRemember('trait_ttl_interval', $interval, fn() => 'interval_val');
        $this->assertEquals('interval_val', $res4);
        $hit4 = $service->doRemember('trait_ttl_interval', $interval, fn() => 'never_called');
        $this->assertEquals('interval_val', $hit4);
    }

    /**
     * Test 4: Query parameter sanitization avoids truncation collisions.
     * Two queries differing after character 255 must NOT collide.
     */
    public function test_query_string_sanitization_does_not_truncate_or_cause_key_collision(): void
    {
        $service = new TestSecurityService();
        $baseString = str_repeat('a', 250);

        // Request A: 250 'a' + 'XYZ' (total 253)
        $this->app->instance('request', Request::create('/test?filter=' . $baseString . 'XYZ'));
        $resA = $service->fetchWithQuery();
        $this->assertEquals('computed_1', $resA);

        // Request B: 250 'a' + '123' (differs at position 251)
        $this->app->instance('request', Request::create('/test?filter=' . $baseString . '123'));
        $resB = $service->fetchWithQuery();
        $this->assertEquals('computed_2', $resB, 'Distinct long query parameters must not collide into identical cache keys.');

        // Re-request A: should hit cache and NOT re-compute
        $this->app->instance('request', Request::create('/test?filter=' . $baseString . 'XYZ'));
        $resA_again = $service->fetchWithQuery();
        $this->assertEquals('computed_1', $resA_again);
        $this->assertEquals(2, $service->callCount, 'Cache hit for Request A must not increment callCount.');
    }

    /**
     * Test 5: Query parameter order invariance and recursive sorting for nested arrays.
     */
    public function test_query_string_order_invariance_and_nested_keys(): void
    {
        $service = new TestSecurityService();

        // Request 1: order: b, a, filter[status], filter[role]
        $this->app->instance('request', Request::create('/test?b=2&a=1&filter[status]=active&filter[role]=admin'));
        $res1 = $service->fetchWithQuery();
        $this->assertEquals('computed_1', $res1);

        // Request 2: reverse order: a, b, filter[role], filter[status]
        $this->app->instance('request', Request::create('/test?a=1&b=2&filter[role]=admin&filter[status]=active'));
        $res2 = $service->fetchWithQuery();
        $this->assertEquals('computed_1', $res2, 'Permuted query parameters and nested arrays must resolve to the identical cache key.');
        $this->assertEquals(1, $service->callCount);
    }

    /**
     * Test 6: Abnormal query payloads (>20 params or >2048 bytes) cleanly bypass cache to prevent DoS.
     */
    public function test_query_string_abnormal_payload_bypasses_cache_to_prevent_dos(): void
    {
        $service = new TestSecurityService();

        // 1. More than 20 parameters (e.g. 25 parameters)
        $params = [];
        for ($i = 0; $i < 25; $i++) {
            $params["param_{$i}"] = "val_{$i}";
        }
        $this->app->instance('request', Request::create('/test?' . http_build_query($params)));

        $res1 = $service->fetchWithQuery();
        $this->assertEquals('computed_1', $res1);

        // Re-calling with >20 params should bypass cache and recompute
        $res2 = $service->fetchWithQuery();
        $this->assertEquals('computed_2', $res2, 'Abnormal query parameter count must bypass cache to protect storage.');

        // 2. Query payload exceeding 2048 bytes serialized
        $hugeParams = [
            'q' => str_repeat('X', 2100),
        ];
        $this->app->instance('request', Request::create('/test?' . http_build_query($hugeParams)));

        $res3 = $service->fetchWithQuery();
        $this->assertEquals('computed_3', $res3);

        $res4 = $service->fetchWithQuery();
        $this->assertEquals('computed_4', $res4, 'Oversized query payload must bypass cache without throwing exceptions.');
    }

    /**
     * Test 7: Chunking race condition: In-flight reader reading old generation does NOT delete active new generation.
     */
    public function test_chunking_race_condition_does_not_purge_new_generation_when_old_chunk_missing(): void
    {
        Config::set('atomic-cache.chunking.threshold', 10);
        Config::set('atomic-cache.chunking.chunk_size', 10);
        Config::set('atomic-cache.self_healing.enabled', true);

        // 1. Write Generation 1 (15 items -> 2 chunks)
        $dataGen1 = array_map(fn($i) => "gen1_item_{$i}", range(1, 15));
        AtomicCache::put('race_test_key', $dataGen1, 3600);

        $store = Cache::store();
        $manifest1 = $store->get('atomic:race_test_key');
        $this->assertIsArray($manifest1);
        $gen1 = $manifest1['generation'];
        $this->assertTrue($store->has("atomic:race_test_key:__chunk__:{$gen1}:0"));

        // 2. Writer publishes Generation 2 (20 items -> 2 chunks)
        $dataGen2 = array_map(fn($i) => "gen2_item_{$i}", range(1, 20));
        AtomicCache::put('race_test_key', $dataGen2, 3600);

        $manifest2 = $store->get('atomic:race_test_key');
        $gen2 = $manifest2['generation'];
        $this->assertNotEquals($gen1, $gen2);
        $this->assertTrue($store->has("atomic:race_test_key:__chunk__:{$gen2}:0"));

        // Generation 1 chunks were pruned by Writer 2
        $this->assertFalse($store->has("atomic:race_test_key:__chunk__:{$gen1}:0"));

        // 3. Simulate an in-flight reader attempting to unpack Generation 1 manifest
        // In previous versions, the missing chunk exception would trigger handleCorruptedCache(),
        // which inadvertently deleted Generation 2's active manifest and data!
        $instance = app(AtomicCacheInstance::class);
        $reflection = new \ReflectionClass($instance);
        $restoreMethod = $reflection->getMethod('restoreChunkedPayload');
        $restoreMethod->setAccessible(true);

        try {
            $restoreMethod->invoke($instance, $manifest1, 'atomic:race_test_key', [], $store);
            $this->fail('Expected ChunkRestorationException was not thrown');
        } catch (\Throwable $e) {
            // Reader triggers self-healing with the exception from gen1
            $healMethod = $reflection->getMethod('handleCorruptedCache');
            $healMethod->setAccessible(true);
            $healResult = $healMethod->invoke($instance, 'atomic:race_test_key', [], $e, 'fallback');
            $this->assertEquals('fallback', $healResult);
        }

        // 4. CRITICAL ASSERTION: The active Generation 2 manifest and chunks MUST REMAIN INTACT!
        $this->assertTrue(
            $store->has('atomic:race_test_key'),
            'Active cache manifest must NOT be purged when an obsolete generation chunk read fails.'
        );
        $this->assertTrue(
            $store->has("atomic:race_test_key:__chunk__:{$gen2}:0"),
            'Active generation chunk 0 must NOT be deleted.'
        );

        $retrieved = AtomicCache::get('race_test_key');
        $this->assertIsArray($retrieved);
        $this->assertCount(20, $retrieved);
        $this->assertEquals('gen2_item_1', $retrieved[0]);
    }

    /**
     * Test 8: Current generation chunk corruption DOES trigger self-healing.
     */
    public function test_chunking_self_healing_properly_purges_when_current_generation_is_genuinely_corrupt(): void
    {
        Config::set('atomic-cache.chunking.threshold', 10);
        Config::set('atomic-cache.chunking.chunk_size', 10);
        Config::set('atomic-cache.self_healing.enabled', true);

        $data = array_map(fn($i) => "item_{$i}", range(1, 15));
        AtomicCache::put('corrupt_test_key', $data, 3600);

        $store = Cache::store();
        $manifest = $store->get('atomic:corrupt_test_key');
        $gen = $manifest['generation'];

        // Corrupt active generation by removing chunk 0
        $store->forget("atomic:corrupt_test_key:__chunk__:{$gen}:0");

        // Reading corrupt key should self-heal and return fallback
        $result = AtomicCache::get('corrupt_test_key', 'self_healed_fallback');
        $this->assertEquals('self_healed_fallback', $result);

        // The broken key should now be purged so next write starts clean
        $this->assertFalse(
            $store->has('atomic:corrupt_test_key'),
            'Genuinely corrupted active generation must be purged by self-healing.'
        );
    }

    /**
     * Test 9: clean_on_put setting controls whether normal put purges prior chunk keys.
     */
    public function test_clean_on_put_configuration_controls_chunk_purge_on_normal_put(): void
    {
        Config::set('atomic-cache.chunking.threshold', 10);
        Config::set('atomic-cache.chunking.chunk_size', 10);

        // Create chunked key
        $data = array_map(fn($i) => "item_{$i}", range(1, 15));
        AtomicCache::put('switch_key', $data, 3600);

        $store = Cache::store();
        $manifest = $store->get('atomic:switch_key');
        $gen = $manifest['generation'];
        $chunkKey = "atomic:switch_key:__chunk__:{$gen}:0";
        $this->assertTrue($store->has($chunkKey));

        // Case A: clean_on_put = false (default)
        // Normal put should overwrite the key without issuing GET to clean chunks
        Config::set('atomic-cache.chunking.clean_on_put', false);
        AtomicCache::put('switch_key', 'normal_value', 3600);
        $this->assertEquals('normal_value', AtomicCache::get('switch_key'));

        // Case B: clean_on_put = true
        // Re-chunk first
        AtomicCache::put('switch_key_2', $data, 3600);
        $gen2 = $store->get('atomic:switch_key_2')['generation'];
        $chunkKey2 = "atomic:switch_key_2:__chunk__:{$gen2}:0";
        $this->assertTrue($store->has($chunkKey2));

        Config::set('atomic-cache.chunking.clean_on_put', true);
        AtomicCache::put('switch_key_2', 'normal_value_2', 3600);
        $this->assertEquals('normal_value_2', AtomicCache::get('switch_key_2'));
        // Old chunk must be explicitly removed when clean_on_put is opted in
        $this->assertFalse($store->has($chunkKey2));
    }

    /**
     * Test 10: Virtual tag invalidation during callback execution discards stale cache write (OCC).
     */
    public function test_virtual_tag_invalidation_during_callback_discards_stale_cache_write(): void
    {
        // 1. First execution: tag is invalidated mid-flight
        $computed = AtomicCache::tags(['products'])->remember('catalog_key', 3600, function () {
            // Simulate an event invalidating 'products' tag while this expensive computation is running
            AtomicCache::clearTags(['products']);
            return ['items' => ['stale_product_A']];
        });

        // 2. The computation itself returns to caller
        $this->assertEquals(['items' => ['stale_product_A']], $computed);

        // 3. CRITICAL: The new virtual tag version must NOT be poisoned with the stale computation
        $this->assertNull(
            AtomicCache::tags(['products'])->get('catalog_key'),
            'Stale data must not be stored under the new virtual tag version if invalidated during computation.'
        );
    }

    /**
     * Test 11: Zero or negative TTL immediately forgets the cached key.
     */
    public function test_zero_or_negative_ttl_forgets_item_immediately(): void
    {
        AtomicCache::put('zero_ttl_key', 'initial_val', 3600);
        $this->assertTrue(AtomicCache::has('zero_ttl_key'));

        // Storing with 0 TTL forgets immediately
        $result = AtomicCache::put('zero_ttl_key', 'new_val', 0);
        $this->assertTrue($result);
        $this->assertFalse(AtomicCache::has('zero_ttl_key'));

        // Storing with expired Carbon timestamp forgets immediately
        AtomicCache::put('expired_carbon_key', 'initial_val_2', 3600);
        $this->assertTrue(AtomicCache::has('expired_carbon_key'));

        AtomicCache::put('expired_carbon_key', 'new_val_2', now()->subMinute());
        $this->assertFalse(AtomicCache::has('expired_carbon_key'));
    }
}