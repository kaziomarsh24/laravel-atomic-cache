<?php

namespace KaziOmar\AtomicCache\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \KaziOmar\AtomicCache\AtomicCache store(?string $name)
 * @method static \KaziOmar\AtomicCache\AtomicCache tags(array|string $tags)
 * @method static \KaziOmar\AtomicCache\AtomicCache ttl(int $seconds)
 * @method static \KaziOmar\AtomicCache\AtomicCache withLock(?int $lockSeconds = null, ?int $waitSeconds = null)
 * @method static \KaziOmar\AtomicCache\AtomicCache forUser(int|string|null $userId = null)
 * @method static mixed remember(string $key, Closure $callback, ?int $ttl = null, array $tags = [], bool $lock = false)
 * @method static mixed rememberWithLock(string $key, Closure $callback, ?int $ttl = null, array $tags = [], ?int $lockSeconds = null, ?int $waitSeconds = null)
 * @method static mixed rememberForUser(string $key, Closure $callback, ?int $ttl = null, int|string|null $userId = null, array $tags = [])
 * @method static mixed get(string $key, mixed $default = null, array $tags = [])
 * @method static bool put(string $key, mixed $value, ?int $ttl = null, array $tags = [])
 * @method static bool forget(string $key, array $tags = [])
 * @method static bool clear(string $key, array $tags = [])
 * @method static bool clearTags(array|string $tags)
 * @method static bool clearItem(string $resource, int|string $id)
 * @method static bool clearCollection(string $resource)
 *
 * @see \KaziOmar\AtomicCache\AtomicCache
 */
class AtomicCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'atomic-cache';
    }
}
