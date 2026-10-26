<?php

namespace KaziOmar\AtomicCache\Concerns;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use KaziOmar\AtomicCache\Exceptions\ChunkRestorationException;
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
        $maxByteSize = (int) config('atomic-cache.chunking.max_bytes', 800000); // 800KB
        if ($count > 10) {
            $sample = array_slice($items, 0, min(5, $count), true);
            $sampleBytes = strlen(serialize($sample));
            $estimatedTotalBytes = ($sampleBytes / max(1, count($sample))) * $count;
            if ($estimatedTotalBytes >= $maxByteSize) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store a large collection or array across multiple driver-safe chunk keys.
     * Uses generation UUIDs to eliminate torn-read race conditions for concurrent readers.
     */
    protected function putChunked(string $effectiveKey, mixed $value, int $ttl, array $tags, Repository $repository): bool
    {
        $isCollection = $value instanceof \Illuminate\Support\Collection;
        $items = $isCollection ? $value->all() : $value;
        $configuredChunkSize = (int) config('atomic-cache.chunking.chunk_size', 1000);
        $maxByteSize = (int) config('atomic-cache.chunking.max_bytes', 800000); // 800KB

        // Dynamically compute chunk size so that chunk payload stays under 800KB
        $sample = array_slice($items, 0, min(5, count($items)), true);
        $sampleBytes = strlen(serialize($sample));
        $bytesPerRow = max(1, (int) round($sampleBytes / max(1, count($sample))));
        $dynamicChunkSize = max(1, min($configuredChunkSize, (int) floor($maxByteSize / $bytesPerRow)));

        $chunks = array_chunk($items, $dynamicChunkSize, true);
        $totalChunks = count($chunks);
        $generation = (string) Str::uuid();

        try {
            // Read previous manifest to cleanly purge old generation chunks after publication
            $oldManifest = null;
            try {
                $existingRaw = $repository->get($effectiveKey);
                if (is_array($existingRaw) && !empty($existingRaw['__ac_chunked'])) {
                    $oldManifest = $existingRaw;
                }
            } catch (Throwable) {}

            // 1. Write all new chunks under the unique generation ID
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkKey = "{$effectiveKey}:__chunk__:{$generation}:{$i}";
                $packedChunk = $this->packPayload($chunks[$i]);
                $stored = $repository->put($chunkKey, $packedChunk, $ttl);
                if (!$stored) {
                    throw new \RuntimeException("Failed to store chunk {$i} for key '{$effectiveKey}'");
                }
            }

            // 2. Atomically point the manifest to the new generation
            $manifest = [
                '__ac_chunked' => true,
                'generation' => $generation,
                'chunks_count' => $totalChunks,
                'total_items' => count($items),
                'is_collection' => $isCollection,
            ];

            $storedManifest = $repository->put($effectiveKey, $manifest, $ttl);
            if (!$storedManifest) {
                throw new \RuntimeException("Failed to store chunk manifest for key '{$effectiveKey}'");
            }

            // 3. Clean up previous generation chunks after the new manifest is successfully published
            if ($oldManifest !== null) {
                $oldGen = $oldManifest['generation'] ?? null;
                $oldCount = (int) ($oldManifest['chunks_count'] ?? 0);
                for ($i = 0; $i < $oldCount; $i++) {
                    if ($oldGen !== null && $oldGen !== $generation) {
                        $repository->forget("{$effectiveKey}:__chunk__:{$oldGen}:{$i}");
                    }
                    $repository->forget("{$effectiveKey}:__chunk__:{$i}");
                }
            }

            return true;
        } catch (Throwable $e) {
            Log::warning("[AtomicCache] Chunking failed for key '{$effectiveKey}': " . $e->getMessage());
            $this->cleanGenerationChunks($effectiveKey, $generation, $totalChunks, $repository);
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
        $generation = $raw['generation'] ?? null;

        $chunkKeys = [];
        for ($i = 0; $i < $chunksCount; $i++) {
            $chunkKeys[] = $generation !== null
                ? "{$key}:__chunk__:{$generation}:{$i}"
                : "{$key}:__chunk__:{$i}";
        }

        $fetchedChunks = $repository->many($chunkKeys);
        $restored = [];

        foreach ($chunkKeys as $i => $chunkKey) {
            $chunkRaw = $fetchedChunks[$chunkKey] ?? null;

            if ($chunkRaw === null) {
                throw new ChunkRestorationException(
                    "Missing chunk {$i} of {$chunksCount} for key '{$key}' (generation: " . ($generation ?? 'none') . ")",
                    $generation
                );
            }

            $chunkData = $this->unpackPayload($chunkRaw, $chunkKey, $tags, $repository);
            if (!is_array($chunkData)) {
                throw new ChunkRestorationException(
                    "Invalid chunk payload for key '{$key}' (generation: " . ($generation ?? 'none') . ")",
                    $generation
                );
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
    protected function cleanExistingChunks(string $effectiveKey, Repository $repository, ?string $excludeGeneration = null): void
    {
        try {
            $raw = $repository->get($effectiveKey);
            if (is_array($raw) && !empty($raw['__ac_chunked'])) {
                $generation = $raw['generation'] ?? null;

                // Don't clean the current active generation
                if ($excludeGeneration !== null && $generation === $excludeGeneration) {
                    return;
                }

                $count = (int) ($raw['chunks_count'] ?? 0);
                for ($i = 0; $i < $count; $i++) {
                    if ($generation !== null) {
                        $repository->forget("{$effectiveKey}:__chunk__:{$generation}:{$i}");
                    }
                    $repository->forget("{$effectiveKey}:__chunk__:{$i}");
                }
            }
        } catch (Throwable) {
            // Ignore errors during chunk cleanup
        }
    }

    /**
     * Clean up chunks for a specific failed generation.
     */
    protected function cleanGenerationChunks(string $effectiveKey, string $generation, int $count, Repository $repository): void
    {
        for ($i = 0; $i < $count; $i++) {
            try {
                $repository->forget("{$effectiveKey}:__chunk__:{$generation}:{$i}");
            } catch (Throwable) {
                // Ignore cleanup errors on failure path
            }
        }
    }

    /**
     * Refresh the TTL of an existing key without re-serializing payload.
     */
    protected function refreshKeyTtl(string $effectiveKey, int $ttl, Repository $repository): void
    {
        try {
            $raw = $repository->get($effectiveKey);
            if ($raw !== null) {
                $repository->put($effectiveKey, $raw, $ttl);
            }

            // If this is a chunked payload, refresh each individual chunk's TTL as well
            if (is_array($raw) && !empty($raw['__ac_chunked'])) {
                $chunksCount = (int) ($raw['chunks_count'] ?? 0);
                $generation = $raw['generation'] ?? null;
                for ($i = 0; $i < $chunksCount; $i++) {
                    $chunkKey = $generation !== null
                        ? "{$effectiveKey}:__chunk__:{$generation}:{$i}"
                        : "{$effectiveKey}:__chunk__:{$i}";
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
