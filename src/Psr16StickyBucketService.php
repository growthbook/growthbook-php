<?php

namespace Growthbook;

use Psr\SimpleCache\CacheInterface;

/**
 * Sticky bucket service backed by any PSR-16 (SimpleCache) implementation.
 *
 * This lets sticky bucket assignments persist across requests using whatever
 * cache backend the application already has (Redis, Memcached, APCu, filesystem,
 * etc.) without adding a hard dependency on any specific client.
 */
class Psr16StickyBucketService extends StickyBucketService
{
    /** @var CacheInterface */
    private $cache;

    /** @var int|null TTL in seconds; null uses the cache implementation's default */
    private $ttl;

    /** @var string Prefix applied to cache keys to avoid collisions with other cached data */
    private $prefix;

    /**
     * @param CacheInterface $cache  Any PSR-16 cache implementation
     * @param int|null       $ttl    TTL in seconds (null = cache default / persist)
     * @param string         $prefix Cache key prefix
     */
    public function __construct(CacheInterface $cache, ?int $ttl = null, string $prefix = "gbStickyBuckets_")
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
        $this->prefix = $prefix;
    }

    /**
     * @param string $attributeName
     * @param string $attributeValue
     * @return array<string,mixed>|null
     */
    public function getAssignments(string $attributeName, string $attributeValue): ?array
    {
        $raw = $this->cache->get($this->cacheKey($attributeName, $attributeValue));
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $doc
     * @return void
     */
    public function saveAssignments(array $doc): void
    {
        $key = $this->cacheKey($doc['attributeName'], $doc['attributeValue']);
        $this->cache->set($key, (string) json_encode($doc), $this->ttl);
    }

    /**
     * Batch-load all documents in a single cache round-trip (PSR-16 getMultiple).
     *
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     */
    public function getAllAssignments(array $attributes): array
    {
        // Map each (prefixed, hashed) cache key back to its logical "attr||value" doc key
        $docKeyByCacheKey = [];
        foreach ($attributes as $attributeName => $attributeValue) {
            $docKeyByCacheKey[$this->cacheKey($attributeName, $attributeValue)] = $this->getKey($attributeName, $attributeValue);
        }

        if (!$docKeyByCacheKey) {
            return [];
        }

        $docs = [];
        foreach ($this->cache->getMultiple(array_keys($docKeyByCacheKey)) as $cacheKey => $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $docs[$docKeyByCacheKey[$cacheKey]] = $decoded;
            }
        }

        return $docs;
    }

    /**
     * Build a PSR-16-safe cache key. The raw "attr||value" key may contain characters
     * reserved by PSR-16 ({}()/\@:), so it is hashed to guarantee a valid key.
     *
     * @param string $attributeName
     * @param string $attributeValue
     * @return string
     */
    private function cacheKey(string $attributeName, string $attributeValue): string
    {
        return $this->prefix . md5($this->getKey($attributeName, $attributeValue));
    }
}
