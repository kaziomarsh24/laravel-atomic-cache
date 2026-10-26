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

        ob_start();

        static::$stack[] = [
            'key' => $key,
            'ttl' => $ttl,
            'tags' => $tags,
            'level' => ob_get_level(),
        ];

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
     * Reset and unwind unclosed buffers down to safe level (safe for exception recovery and Octane).
     * Ensures outer Laravel response output buffers are never accidentally closed.
     */
    public static function reset(): void
    {
        while (!empty(static::$stack)) {
            $context = array_pop(static::$stack);
            $targetLevel = max(0, ($context['level'] ?? 1) - 1);
            while (ob_get_level() > $targetLevel) {
                ob_end_clean();
            }
        }
    }
}
