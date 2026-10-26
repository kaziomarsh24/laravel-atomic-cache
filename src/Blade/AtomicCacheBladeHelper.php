<?php

namespace KaziOmar\AtomicCache\Blade;

use KaziOmar\AtomicCache\Facades\AtomicCache;

class AtomicCacheBladeHelper
{
    protected static array $stack = [];

    /**
     * Set up the cache block. Returns true if block should be rendered (cache miss), false if cached.
     */
    public static function setUp(string $key, ?int $ttl = null, array $tags = []): bool
    {
        $cached = AtomicCache::tags($tags)->get($key);

        if ($cached !== null) {
            echo $cached;
            return false;
        }

        static::$stack[] = [
            'key' => $key,
            'ttl' => $ttl,
            'tags' => $tags,
        ];

        ob_start();
        return true;
    }

    /**
     * Tear down the cache block, capture rendered HTML, store in cache, and output.
     */
    public static function tearDown(): string
    {
        if (empty(static::$stack)) {
            return '';
        }

        $context = array_pop(static::$stack);
        $html = ob_get_clean();

        AtomicCache::tags($context['tags'])->put(
            $context['key'],
            $html,
            $context['ttl']
        );

        return $html;
    }

    /**
     * Reset and unwind all unclosed buffers and stack (safe for exception recovery and Octane).
     */
    public static function reset(): void
    {
        while (!empty(static::$stack)) {
            array_pop(static::$stack);
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
    }
}
