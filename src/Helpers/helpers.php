<?php

use KaziOmar\AtomicCache\AtomicCache;

if (!function_exists('atomic_cache')) {
    /**
     * Get the AtomicCache instance or quickly remember a key.
     *
     * @param string|null $key
     * @param \Closure|null $callback
     * @param int|null $ttl
     * @return \KaziOmar\AtomicCache\AtomicCache|mixed
     */
    function atomic_cache(?string $key = null, ?Closure $callback = null, ?int $ttl = null): mixed
    {
        $instance = app('atomic-cache');

        if ($key === null) {
            return $instance;
        }

        if ($callback === null) {
            return $instance->get($key);
        }

        return $instance->remember($key, $callback, $ttl);
    }
}
