# Driver Compatibility Matrix

Atomic Cache dynamically evaluates your cache store: stores supporting native tagging (`redis`, `memcached`, `array`) use native driver mechanisms, while non-taggable stores (`file`, `database`, `sqlite`, `dynamodb`) automatically receive virtual tagging without throwing runtime exceptions.

---

## Compatibility Overview

| Driver | Tag Support | Stampede Locks | CI Matrix Status | Notes |
| :--- | :--- | :--- | :--- | :--- |
| **`redis`** | **Native** | Yes | Tested in CI | Recommended for high-concurrency production workloads. In production with high writes, schedule Laravel's `php artisan cache:prune-stale-tags`. |
| **`file`** | **Virtual** | Yes | Tested in CI | Default local development store. Virtual tags provide zero-crash tagging. FileStore supports locks natively. |
| **`database`** | **Virtual** | Yes | Compatible | Works on MySQL, PostgreSQL, SQLite, and SQL Server without extra setup. |
| **`array`** | **Native** | Yes | Tested in CI | In-memory per process (ArrayStore is taggable). Ideal for PHPUnit and automated test suites. |
| **`memcached`**| **Native** | Yes | Compatible | 1 MB item limit: the transparent 800 KB chunk guard and Gzip compression protect against key overflow. |
| **`dynamodb`** | **Virtual** | Yes | Compatible | Items limited to 400 KB; configure `ATOMIC_CACHE_CHUNK_MAX_BYTES` below 400,000. |

---

## Virtual Tagging vs Native Tagging

### Native Tagging Stores (`redis`, `memcached`, `array`)
When running on Redis or Memcached, Atomic Cache forwards tagging operations directly to Laravel's `TaggedCache` implementation. This preserves high-speed atomic set invalidation native to the store.

### Non-Taggable Stores (`file`, `database`, `sqlite`, `dynamodb`)
Calling Laravel's native `Cache::tags(['products'])` on non-taggable drivers throws:

```text
BadMethodCallException: This cache store does not support tagging.
```

Atomic Cache intercepts these calls and applies **Virtual Tag Versioning**:
1. Maintains a persistent UUID version token for each active tag.
2. Embeds the hashed tag version into the physical cache key (`{prefix}:{key}:vt:{hash}`).
3. When `AtomicCache::clearTags(['products'])` is called, the tag version token is updated in **0ms**.
4. All existing cache entries under that tag immediately experience a cache miss, with zero slow directory scans or heavy SQL deletes.

::: tip Safe for Monoliths and Micro-Services
Virtual tagging enables you to develop locally using the lightweight `file` or `sqlite` cache driver with full tagging support, then deploy to production on Redis without altering a single line of application code.
:::
