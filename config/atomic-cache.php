<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable / Disable Atomic Cache
    |--------------------------------------------------------------------------
    |
    | When disabled, all cache remember calls execute the closure directly
    | without caching, useful for local debugging or automated testing.
    |
    */
    'enabled' => env('ATOMIC_CACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Cache TTL
    |--------------------------------------------------------------------------
    |
    | Default Time-To-Live in seconds for cached items when none is provided.
    | Default: 3600 seconds (1 hour).
    |
    */
    'default_ttl' => env('ATOMIC_CACHE_DEFAULT_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Cache Store Driver
    |--------------------------------------------------------------------------
    |
    | The specific cache store to use (e.g., 'redis', 'memcached', 'file').
    | Set to null to use your application's default cache store.
    |
    */
    'store' => env('ATOMIC_CACHE_STORE', null),

    /*
    |--------------------------------------------------------------------------
    | Global Key Prefix
    |--------------------------------------------------------------------------
    |
    | A global namespace prefix applied to all atomic cache keys.
    |
    */
    'prefix' => env('ATOMIC_CACHE_PREFIX', 'atomic'),

    /*
    |--------------------------------------------------------------------------
    | Stampede Protection (Atomic Distributed Lock)
    |--------------------------------------------------------------------------
    |
    | When enabled, concurrent requests for an expired or missing key wait for
    | the first process to compute and store the result, preventing database
    | stampedes and CPU spikes.
    |
    */
    'stampede' => [
        'enabled' => env('ATOMIC_CACHE_STAMPEDE_ENABLED', true),
        'lock_seconds' => env('ATOMIC_CACHE_LOCK_SECONDS', 10),
        'wait_seconds' => env('ATOMIC_CACHE_WAIT_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Payload Compression
    |--------------------------------------------------------------------------
    |
    | Automatically compress large payloads using gzip (zlib) when the serialized
    | size exceeds the threshold. Saves up to 70-80% memory on Redis/Memcached.
    |
    */
    'compression' => [
        'enabled' => env('ATOMIC_CACHE_COMPRESSION_ENABLED', true),
        'threshold' => env('ATOMIC_CACHE_COMPRESSION_THRESHOLD', 51200), // 50 KB
        'level' => env('ATOMIC_CACHE_COMPRESSION_LEVEL', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transparent Chunking
    |--------------------------------------------------------------------------
    |
    | Automatically splits large datasets (arrays or collections) into smaller
    | driver-safe chunks to prevent memory overflows and driver limits (Memcached 1MB).
    | Seamlessly reassembles data upon retrieval with key preservation.
    |
    */
    'chunking' => [
        'enabled' => env('ATOMIC_CACHE_CHUNKING_ENABLED', true),
        'threshold' => env('ATOMIC_CACHE_CHUNKING_THRESHOLD', 1000), // minimum items to trigger chunking
        'chunk_size' => env('ATOMIC_CACHE_CHUNK_SIZE', 1000), // items per chunk
    ],

    /*
    |--------------------------------------------------------------------------
    | Write Deduplication (Cache DNA)
    |--------------------------------------------------------------------------
    |
    | Hashes payload before writing. If identical content is already cached with
    | valid remaining TTL, redundant write operations are skipped to save I/O and CPU.
    |
    */
    'deduplication' => [
        'enabled' => env('ATOMIC_CACHE_DEDUPLICATION_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-Healing Recovery
    |--------------------------------------------------------------------------
    |
    | When enabled, corrupted or incomplete cached values are automatically
    | cleared, logged, and re-computed on the fly rather than throwing an exception.
    |
    */
    'self_healing' => [
        'enabled' => env('ATOMIC_CACHE_SELF_HEALING', true),
        'log_corrupted' => env('ATOMIC_CACHE_LOG_CORRUPTED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTL Jitter (Thundering Herd Prevention)
    |--------------------------------------------------------------------------
    |
    | Adds a small random variance (percentage) to cache TTLs so that multiple
    | keys created together do not expire at the exact same second.
    |
    */
    'jitter' => [
        'enabled' => env('ATOMIC_CACHE_JITTER_ENABLED', false),
        'percentage' => env('ATOMIC_CACHE_JITTER_PERCENTAGE', 5), // ±5%
    ],
];
