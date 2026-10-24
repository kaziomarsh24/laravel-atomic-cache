<?php

use KaziOmar\AtomicCache\AtomicCache;

if (!function_exists('atomic_cache')) {
    /**
     * Get the AtomicCache instance or quickly remember a key.
     *
     * Supports:
     * - atomic_cache() -> returns AtomicCache instance for fluent chaining
     * - atomic_cache('key') -> retrieves cached value or null
     * - atomic_cache('key', 3600, fn() => ...) -> remembers value with TTL
     * - atomic_cache('key', fn() => ..., 3600) -> remembers value with TTL (flexible)
     * - atomic_cache('key', fn() => ...) -> remembers value with default TTL
     *
     * @param string|null $key
     * @param mixed $arg2
     * @param mixed $arg3
     * @return \KaziOmar\AtomicCache\AtomicCache|mixed
     */
    function atomic_cache(?string $key = null, mixed $arg2 = null, mixed $arg3 = null): mixed
    {
        $instance = app('atomic-cache');

        if ($key === null) {
            return $instance;
        }

        if ($arg2 === null && $arg3 === null) {
            return $instance->get($key);
        }

        return $instance->remember($key, $arg2, $arg3);
    }
}