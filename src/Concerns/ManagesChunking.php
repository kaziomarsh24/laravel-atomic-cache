<?php

namespace KaziOmar\AtomicCache\Concerns;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

trait ManagesChunking
{
    /**
     * Determine if a value should be split across multiple chunk keys.
     */
    protected function shouldChunk(mixed $value): bool
    {
        if (!config('atomic-cache.chunking.enabled', true)) {
            return false;
        }

        $items = $value instanceof \Illuminate\Support\Collection ? $value->all() : $value;
        if (!is_array($items)) {
            return false;
        }

        $threshold = (int) config('atomic-cache.chunking.threshold', 1000);
        $chunkSize = (int) config('atomic-cache.chunking.chunk_size', 1000);
        $count = count($items);

        // 1. Item count threshold
        if ($count >= $threshold && $count > $chunkSize) {
            return true;
        }

        // 2. Large row / memory threshold protection for 1MB limits (e.g. Memcached)
        $maxByteSize = (int) config('atomic-cache.chunking.max_bytes', 900000); // ~900KB
        if ($count > 10) {
            $sample = array_slice($items, 0, 5, true);
            $sampleBytes = strlen(serialize($sample));
            $estimatedSize = ($sampleBytes / 5) * $count;
            if ($estimatedSize >= $maxByteSize) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store a large collection or array across multiple driver-safe chunk keys.
     * Overwrites chunks in-place before updating the manifest to prevent torn-read race conditions.
     */
    protected function putChunked(string $effectiveKey, mixed $value, int $ttl, array $tags, Repository $repository): bool
    {
        $isCollection = $value instanceof \Illuminate\Support\Collection;
        $items = $isCollection ? $value->all() : $value;
        $chunkSize = (int) config('atomic-cache.chunking.chunk_size', 1000);

        // Preserve associative and numeric keys
        $chunks = array_chunk($items, $chunkSize, true);
        $totalChunks = count($chunks);

        // Inspect prior manifest to identify any leftover chunks to purge after write
        $priorCount = 0;
        try {
            $priorManifest = $repository->get($effectiveKey);
            if (is_array($priorManifest) && !empty($priorManifest['__ac_chunked'])) {
                $priorCount = (int) ($priorManifest['chunks_count'] ?? 0);
            }
        } catch (Throwable) {
            // Ignore prior manifest lookup error
        }

        try {
            // 1. Write all new chunks in place without wiping them first
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkKey = "{$effectiveKey}:__chunk__:{$i}";
                $packedChunk = $this->packPayload($chunks[$i]);
                $stored = $repository->put($chunkKey, $packedChunk, $ttl);
                if (!$stored) {
                    throw new \RuntimeException("Failed to store chunk {$i} for key '{$effectiveKey}'");
                }
            }

            // 2. Atomically update the manifest to the new count
            $manifest = [
                '__ac_chunked' => true,
                'chunks_count' => $totalChunks,
                'total_items' => count($items),
                'is_collection' => $isCollection,
            ];

            $storedManifest = $repository->put($effectiveKey, $manifest, $ttl);
            if (!$storedManifest) {
                throw new \RuntimeException("Failed to store chunk manifest for key '{$effectiveKey}'");
            }

            // 3. Purge any leftover orphaned chunks if the new dataset is smaller than previous
            if ($priorCount > $totalChunks) {
                for ($i = $totalChunks; $i < $priorCount; $i++) {
                    $repository->forget("{$effectiveKey}:__chunk__:{$i}");
                }
            }

            return true;
        } catch (Throwable $e) {
            Log::warning("[AtomicCache] Chunking failed for key '{$effectiveKey}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Restore and recombine a chunked array or collection.
     */
    protected function restoreChunkedPayload(array $raw, string $key, array $tags, Repository $repository): mixed
    {
        $chunksCount = (int) ($raw['chunks_count'] ?? 0);
        $isCollection = !empty($raw['is_collection']);

        $chunkKeys = [];
        for ($i = 0; $i < $chunksCount; $i++) {
            $chunkKeys[] = "{$key}:__chunk__:{$i}";
        }

        $fetchedChunks = $repository->many($chunkKeys);
        $restored = [];

        foreach ($chunkKeys as $i => $chunkKey) {
            $chunkRaw = $fetchedChunks[$chunkKey] ?? null;

            if ($chunkRaw === null) {
                throw new \RuntimeException("Missing chunk {$i} of {$chunksCount} for key '{$key}'");
            }

            $chunkData = $this->unpackPayload($chunkRaw, $chunkKey, $tags, $repository);
            if (!is_array($chunkData)) {
                throw new \RuntimeException("Invalid chunk payload for key '{$key}'");
            }

            // Preserve associative keys and sparse numeric keys
            foreach ($chunkData as $k => $v) {
                $restored[$k] = $v;
            }
        }

        return $isCollection ? collect($restored) : $restored;
    }

    /**
     * Clean up any existing chunk keys for a given manifest key.
     */
    protected function cleanExistingChunks(string $effectiveKey, Repository $repository): void
    {
        try {
            $raw = $repository->get($effectiveKey);
            if (is_array($raw) && !empty($raw['__ac_chunked'])) {
                $count = (int) ($raw['chunks_count'] ?? 0);
                for ($i = 0; $i < $count; $i++) {
                    $repository->forget("{$effectiveKey}:__chunk__:{$i}");
                }
            }
        } catch (Throwable) {
            // Ignore errors during chunk cleanup
        }
    }

    /**
     * Refresh the TTL of an existing key without re-serializing payload.
     */
    protected function refreshKeyTtl(string $effectiveKey, int $ttl, Repository $repository): void
    {
        try {
            $rawStore = $this->getRawStore();

            // 1. Fast path for Redis: Use native EXPIRE command to avoid network round-trip overhead
            if (method_exists($rawStore, 'getRedis')) {
                try {
                    $rawStore->getRedis()->expire($effectiveKey, $ttl);
                    return;
                } catch (Throwable) {
                    // Fall back to standard repository refresh
                }
            }

            // 2. Standard refresh fallback
            $raw = $repository->get($effectiveKey);
            if ($raw !== null) {
                $repository->put($effectiveKey, $raw, $ttl);
            }

            // If this is a chunked payload, refresh each individual chunk's TTL as well
            if (is_array($raw) && !empty($raw['__ac_chunked'])) {
                $chunksCount = (int) ($raw['chunks_count'] ?? 0);
                for ($i = 0; $i < $chunksCount; $i++) {
                    $chunkKey = "{$effectiveKey}:__chunk__:{$i}";
                    $chunkRaw = $repository->get($chunkKey);
                    if ($chunkRaw !== null) {
                        $repository->put($chunkKey, $chunkRaw, $ttl);
                    }
                }
            }
        } catch (Throwable) {
            // Silently ignore TTL refresh errors
        }
    }
}
