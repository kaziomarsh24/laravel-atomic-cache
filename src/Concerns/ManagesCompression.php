<?php

namespace KaziOmar\AtomicCache\Concerns;

use Illuminate\Contracts\Cache\Repository;
use Throwable;

trait ManagesCompression
{
    /**
     * Pack payload with optional gzip compression if threshold is met.
     */
    protected function packPayload(mixed $value): mixed
    {
        $compressionEnabled = config('atomic-cache.compression.enabled', true);
        $threshold = (int) config('atomic-cache.compression.threshold', 51200); // 50KB
        $level = (int) config('atomic-cache.compression.level', 6);

        if (!$compressionEnabled || !extension_loaded('zlib')) {
            return [
                '__ac_compressed' => false,
                'data' => $value,
            ];
        }

        try {
            $serialized = serialize($value);

            if (strlen($serialized) >= $threshold) {
                $compressed = gzcompress($serialized, $level);
                if ($compressed !== false) {
                    return [
                        '__ac_compressed' => true,
                        'data' => base64_encode($compressed),
                    ];
                }
            }
        } catch (Throwable) {
            // Serialization failed, fallback to raw
        }

        return [
            '__ac_compressed' => false,
            'data' => $value,
        ];
    }

    /**
     * Unpack payload, decompressing or restoring chunked data if necessary.
     */
    protected function unpackPayload(mixed $raw, string $key, array $tags, ?Repository $repository = null): mixed
    {
        if (!is_array($raw)) {
            return $raw;
        }

        // 1. Handle Transparent Chunking
        if (!empty($raw['__ac_chunked'])) {
            $store = $repository ?? $this->getRepository($tags);
            return $this->restoreChunkedPayload($raw, $key, $tags, $store);
        }

        // 2. Handle Payload Gzip Compression
        if (!empty($raw['__ac_compressed'])) {
            if (!extension_loaded('zlib')) {
                throw new \RuntimeException("[AtomicCache] Cannot decompress cache key '{$key}': ext-zlib is not loaded.");
            }

            $encoded = $raw['data'] ?? '';
            $compressed = base64_decode((string) $encoded, true);

            if ($compressed === false) {
                throw new \RuntimeException("[AtomicCache] Base64 decode failed for compressed cache key '{$key}'");
            }

            $decompressed = @gzuncompress($compressed);
            if ($decompressed === false) {
                throw new \RuntimeException("[AtomicCache] Gzip decompression failed for cache key '{$key}'");
            }

            $unserialized = @unserialize($decompressed);
            if ($unserialized === false && $decompressed !== serialize(false)) {
                throw new \RuntimeException("[AtomicCache] Unserialization failed for decompressed key '{$key}'");
            }

            return $unserialized;
        }

        // 3. Normal wrapped payload
        if (array_key_exists('__ac_compressed', $raw) && array_key_exists('data', $raw)) {
            return $raw['data'];
        }

        return $raw;
    }
}
