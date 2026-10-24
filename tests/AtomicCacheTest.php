<?php

namespace KaziOmar\AtomicCache\Tests;

use KaziOmar\AtomicCache\Facades\AtomicCache;

class AtomicCacheTest extends TestCase
{
    public function test_can_put_and_get_cached_value(): void
    {
        AtomicCache::put('greeting', 'Hello World', 60);

        $this->assertEquals('Hello World', AtomicCache::get('greeting'));
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

    public function test_forget_and_clear_removes_value(): void
    {
        AtomicCache::put('temp', 'value', 60);
        $this->assertEquals('value', AtomicCache::get('temp'));

        AtomicCache::clear('temp');
        $this->assertNull(AtomicCache::get('temp'));
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
        atomic_cache('helper_key', fn() => 'from_helper', 60);

        $this->assertEquals('from_helper', atomic_cache('helper_key'));
    }
}
