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
 * @method static \KaziOmar\AtomicCache\AtomicCache withJitter(?float $percentage = null)
 * @method static mixed remember(string $key, mixed $ttlOrCallback, mixed $callbackOrTtl = null, array $tags = [], bool $lock = false)
 * @method static mixed rememberWithLock(string $key, mixed $ttlOrCallback, mixed $callbackOrTtl = null, array $tags = [], ?int $lockSeconds = null, ?int $waitSeconds = null)
 * @method static mixed rememberForUser(string $key, mixed $ttlOrCallback, mixed $callbackOrTtl = null, int|string|null $userId = null, array $tags = [])
 * @method static mixed get(string $key, mixed $default = null, array $tags = [])
 * @method static bool put(string $key, mixed $value, ?int $ttl = null, array $tags = [])
 * @method static bool has(string $key, array $tags = [])
 * @method static bool forever(string $key, mixed $value, array $tags = [])
 * @method static mixed pull(string $key, mixed $default = null, array $tags = [])
 * @method static bool forget(string $key, array $tags = [])
 * @method static bool clear(string $key, array $tags = [])
 * @method static bool clearTags(array|string $tags)
 * @method static bool flushTags(array|string $tags)
 * @method static bool flush()
 * @method static bool clearItem(string $resource, int|string $id)
 * @method static bool forgetItem(string $resource, int|string $id)
 * @method static bool clearCollection(string $resource)
 * @method static bool flushCollection(string $resource)
 * @method static bool supportsTags(\Illuminate\Contracts\Cache\Repository $store)
 * @method static bool supportsLock(\Illuminate\Contracts\Cache\Repository $store)
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