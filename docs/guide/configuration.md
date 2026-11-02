# Configuration Reference

Publish `config/atomic-cache.php` using Artisan:

```bash
php artisan vendor:publish --tag="atomic-cache-config"
```

---

## Configuration File Reference

```php
return [
    'enabled' => env('ATOMIC_CACHE_ENABLED', true),
    'bypass_during_transactions' => env('ATOMIC_CACHE_BYPASS_DURING_TRANSACTIONS', true),
    'strict_mode' => env('ATOMIC_CACHE_STRICT_MODE', null), // null = active in local (app()->isLocal())
    'default_ttl' => env('ATOMIC_CACHE_DEFAULT_TTL', 3600),
    'store' => env('ATOMIC_CACHE_STORE', null),
    'prefix' => env('ATOMIC_CACHE_PREFIX', 'atomic'),

    'stampede' => [
        'enabled' => env('ATOMIC_CACHE_STAMPEDE_ENABLED', true),
        'lock_seconds' => env('ATOMIC_CACHE_LOCK_SECONDS', 10),
        'wait_seconds' => env('ATOMIC_CACHE_WAIT_SECONDS', 5),
    ],

    'compression' => [
        'enabled' => env('ATOMIC_CACHE_COMPRESSION_ENABLED', true),
        'threshold' => env('ATOMIC_CACHE_COMPRESSION_THRESHOLD', 51200), // 50 KB
        'level' => env('ATOMIC_CACHE_COMPRESSION_LEVEL', 6),
    ],

    'chunking' => [
        'enabled' => env('ATOMIC_CACHE_CHUNKING_ENABLED', true),
        'threshold' => env('ATOMIC_CACHE_CHUNKING_THRESHOLD', 1000),
        'chunk_size' => env('ATOMIC_CACHE_CHUNK_SIZE', 1000),
        'max_bytes' => env('ATOMIC_CACHE_CHUNK_MAX_BYTES', 800000), // 800 KB ceiling
        'clean_on_put' => env('ATOMIC_CACHE_CHUNKING_CLEAN_ON_PUT', false),
    ],

    'deduplication' => [
        'enabled' => env('ATOMIC_CACHE_DEDUPLICATION_ENABLED', false),
    ],

    'self_healing' => [
        'enabled' => env('ATOMIC_CACHE_SELF_HEALING', true),
        'log_corrupted' => env('ATOMIC_CACHE_LOG_CORRUPTED', true),
    ],

    'jitter' => [
        'enabled' => env('ATOMIC_CACHE_JITTER_ENABLED', false),
        'percentage' => env('ATOMIC_CACHE_JITTER_PERCENTAGE', 5), // ±5%
    ],

    'ignored_query_parameters' => [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'dclid', 'wbraid', 'gbraid', 'ttclid', 'msclkid',
        'mc_cid', 'mc_eid', '_ga', '_gl', '_gid', 'ref',
    ],

    'query_limits' => [
        'max_params' => env('ATOMIC_CACHE_QUERY_MAX_PARAMS', 20),
        'max_bytes' => env('ATOMIC_CACHE_QUERY_MAX_BYTES', 2048),
    ],
];
```

---

## Environment Variables (.env) Reference

| Variable | Default | Description |
| :--- | :--- | :--- |
| `ATOMIC_CACHE_ENABLED` | `true` | Master switch to enable or disable atomic caching globally |
| `ATOMIC_CACHE_DEFAULT_TTL` | `3600` | Default cache lifetime in seconds when none is specified |
| `ATOMIC_CACHE_STORE` | `null` | Dedicated cache store (e.g. `'redis'`); `null` uses `config('cache.default')` |
| `ATOMIC_CACHE_PREFIX` | `atomic` | Key namespace prefix (in multi-tenant apps, set uniquely per tenant) |
| `ATOMIC_CACHE_STAMPEDE_ENABLED` | `true` | Enable atomic distributed locking by default |
| `ATOMIC_CACHE_LOCK_SECONDS` | `10` | Maximum lock hold duration during closure calculation |
| `ATOMIC_CACHE_WAIT_SECONDS` | `5` | Maximum seconds concurrent requests will wait for computation |
| `ATOMIC_CACHE_COMPRESSION_ENABLED`| `true` | Auto-compress payloads exceeding compression threshold |
| `ATOMIC_CACHE_COMPRESSION_THRESHOLD`| `51200` | Minimum serialized byte size to trigger Gzip compression (50 KB) |
| `ATOMIC_CACHE_CHUNKING_ENABLED` | `true` | Auto-partition large datasets into driver-safe chunk keys |
| `ATOMIC_CACHE_CHUNKING_THRESHOLD` | `1000` | Item count threshold required to trigger transparent chunking |
| `ATOMIC_CACHE_CHUNK_SIZE` | `1000` | Item count per partitioned chunk |
| `ATOMIC_CACHE_CHUNK_MAX_BYTES` | `800000` | Maximum byte ceiling per chunk payload (800 KB for Memcached 1MB limit) |
| `ATOMIC_CACHE_CHUNKING_CLEAN_ON_PUT`| `false` | When true, non-chunked put purges prior chunk keys eagerly |
| `ATOMIC_CACHE_DEDUPLICATION_ENABLED`| `false` | Opt-in DNA hashing: skips compression and chunk re-splitting if identical content is cached |
| `ATOMIC_CACHE_JITTER_ENABLED` | `false` | Enable random TTL variance globally |
| `ATOMIC_CACHE_JITTER_PERCENTAGE` | `5` | Jitter variance percentage (±5%) |
| `ATOMIC_CACHE_QUERY_MAX_PARAMS` | `20` | Max allowed sanitized HTTP query parameters before bypassing cache |
| `ATOMIC_CACHE_QUERY_MAX_BYTES` | `2048` | Max allowed serialized query string size before bypassing cache |
