<?php

namespace Growthbook;

/**
 * LRU (Least Recently Used) cache for storing ETags.
 * 
 * This cache has a maximum capacity and automatically evicts the least recently
 * accessed entries when the capacity is exceeded.
 */

class LruETagCache
{
    /**
     * @var int Maximum number of entries to store
     */
    private int $maxSize;

    /**
     * @var array<string, string> The cache storage (URL => ETag)
     */
    private array $cache = [];

    /**
     * @param int $maxSize Maximum number of entries to store (default: 100)
     */
    public function __construct(int $maxSize = 100)
    {
        $this->maxSize = max(1, $maxSize);
    }

    /**
     * Get the ETag for a URL, updating its access order.
     *
     * @param string $url The URL to look up
     * @return string|null The ETag value, or null if not found
     */
    public function get(string $url): ?string
    {
        if (!array_key_exists($url, $this->cache)) {
            return null;
        }

        $etag = $this->cache[$url];
        unset($this->cache[$url]);
        $this->cache[$url] = $etag;

        return $this->cache[$url];
    }

    /**
     * Store an ETag for a URL.
     *
     * If the ETag is null, the entry will be removed.
     * If capacity is exceeded, the least recently used entry will be evicted.
     *
     * @param string $url The URL to store
     * @param string|null $etag The ETag value, or null to remove
     */
    public function put(string $url, ?string $etag): void
    {
        if ($etag === null) {
            $this->remove($url);
            return;
        }

        // If exists, remove it first (will re-add at end)
        if (array_key_exists($url, $this->cache)) {
            unset($this->cache[$url]);
        } else {
            // New entry - check if we need to evict
            if (count($this->cache) >= $this->maxSize) {
                // Remove first entry (least recently used)
                // array_shift removes and returns first element
                array_shift($this->cache);
            }
        }

        // Add at end (most recently used)
        $this->cache[$url] = $etag;
    }

    /**
     * Remove an entry from the cache.
     *
     * @param string $url The URL to remove
     * @return string|null The removed ETag value, or null if not found
     */
    public function remove(string $url): ?string
    {
        if (!array_key_exists($url, $this->cache)) {
            return null;
        }

        $value = $this->cache[$url];
        unset($this->cache[$url]);
        return $value;
    }

    /**
     * Check if a URL exists in the cache.
     *
     * @param string $url The URL to check
     * @return bool True if the URL exists in the cache
     */
    public function contains(string $url): bool
    {
        return array_key_exists($url, $this->cache);
    }

    /**
     * Get the current number of entries in the cache.
     *
     * @return int The number of entries
     */
    public function size(): int
    {
        return count($this->cache);
    }

    /**
     * Clear all entries from the cache.
     */
    public function clear(): void
    {
        $this->cache = [];
    }
}
