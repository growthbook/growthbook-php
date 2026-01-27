<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\LruETagCache;
use PHPUnit\Framework\TestCase;

final class LruETagCacheTest extends TestCase
{
    private LruETagCache $cache;

    protected function setUp(): void
    {
        $this->cache = new LruETagCache(3);
    }

    public function testBasicPutAndGet(): void
    {
        $this->cache->put("url1", "etag1");
        $this->cache->put("url2", "etag2");

        $this->assertSame("etag1", $this->cache->get("url1"));
        $this->assertSame("etag2", $this->cache->get("url2"));
        $this->assertNull($this->cache->get("url3"));
    }

    public function testLruEvictionWhenCapacityExceeded(): void
    {
        // Fill cache to capacity
        $this->cache->put("url1", "etag1");
        $this->cache->put("url2", "etag2");
        $this->cache->put("url3", "etag3");

        // Add one more, should evict url1 (least recently used)
        $this->cache->put("url4", "etag4");

        $this->assertNull($this->cache->get("url1")); // Evicted
        $this->assertSame("etag2", $this->cache->get("url2"));
        $this->assertSame("etag3", $this->cache->get("url3"));
        $this->assertSame("etag4", $this->cache->get("url4"));
        $this->assertSame(3, $this->cache->size());
    }

    public function testAccessingEntryUpdatesItsPositionInLru(): void
    {
        $this->cache->put("url1", "etag1");
        $this->cache->put("url2", "etag2");
        $this->cache->put("url3", "etag3");

        // Access url1 to make it recently used
        $this->cache->get("url1");

        // Add url4, should evict url2 (now the least recently used)
        $this->cache->put("url4", "etag4");

        $this->assertSame("etag1", $this->cache->get("url1")); // Still present
        $this->assertNull($this->cache->get("url2")); // Evicted
        $this->assertSame("etag3", $this->cache->get("url3"));
        $this->assertSame("etag4", $this->cache->get("url4"));
    }

    public function testPutNullRemovesEntry(): void
    {
        $this->cache->put("url1", "etag1");
        $this->assertSame("etag1", $this->cache->get("url1"));

        $this->cache->put("url1", null);
        $this->assertNull($this->cache->get("url1"));
        $this->assertSame(0, $this->cache->size());
    }

    public function testRemoveOperation(): void
    {
        $this->cache->put("url1", "etag1");
        $this->cache->put("url2", "etag2");

        $removed = $this->cache->remove("url1");

        $this->assertSame("etag1", $removed);
        $this->assertNull($this->cache->get("url1"));
        $this->assertSame(1, $this->cache->size());
    }

    public function testRemoveNonExistentEntry(): void
    {
        $removed = $this->cache->remove("nonexistent");

        $this->assertNull($removed);
    }

    public function testClearOperation(): void
    {
        $this->cache->put("url1", "etag1");
        $this->cache->put("url2", "etag2");
        $this->cache->put("url3", "etag3");

        $this->cache->clear();

        $this->assertSame(0, $this->cache->size());
        $this->assertNull($this->cache->get("url1"));
        $this->assertNull($this->cache->get("url2"));
        $this->assertNull($this->cache->get("url3"));
    }

    public function testContainsOperation(): void
    {
        $this->cache->put("url1", "etag1");

        $this->assertTrue($this->cache->contains("url1"));
        $this->assertFalse($this->cache->contains("url2"));
    }

    public function testSizeOperation(): void
    {
        $this->assertSame(0, $this->cache->size());

        $this->cache->put("url1", "etag1");
        $this->assertSame(1, $this->cache->size());

        $this->cache->put("url2", "etag2");
        $this->assertSame(2, $this->cache->size());

        $this->cache->put("url3", "etag3");
        $this->assertSame(3, $this->cache->size());

        // Adding a 4th entry should keep size at 3 (evicts one)
        $this->cache->put("url4", "etag4");
        $this->assertSame(3, $this->cache->size());
    }

    public function testUpdatingExistingEntryDoesNotGrowCache(): void
    {
        $this->cache->put("url1", "etag1");
        $this->cache->put("url2", "etag2");
        $this->assertSame(2, $this->cache->size());

        // Update existing entry
        $this->cache->put("url1", "etag1-updated");

        // Size should still be 2
        $this->assertSame(2, $this->cache->size());
        $this->assertSame("etag1-updated", $this->cache->get("url1"));
    }

    public function testLargeCacheOperations(): void
    {
        $largeCache = new LruETagCache(100);

        // Add 150 items
        for ($i = 0; $i < 150; $i++) {
            $largeCache->put("url$i", "etag$i");
        }

        // Should only have 100 items (the most recent ones)
        $this->assertSame(100, $largeCache->size());

        // First 50 should be evicted
        for ($i = 0; $i < 50; $i++) {
            $this->assertNull($largeCache->get("url$i"));
        }

        // Last 100 should be present
        for ($i = 50; $i < 150; $i++) {
            $this->assertSame("etag$i", $largeCache->get("url$i"));
        }
    }

    public function testDefaultMaxSize(): void
    {
        $defaultCache = new LruETagCache();

        // Add 101 items
        for ($i = 0; $i < 101; $i++) {
            $defaultCache->put("url$i", "etag$i");
        }

        // Should only have 100 items (default max size)
        $this->assertSame(100, $defaultCache->size());

        // First entry should be evicted
        $this->assertNull($defaultCache->get("url0"));

        // Last 100 should be present
        for ($i = 1; $i <= 100; $i++) {
            $this->assertSame("etag$i", $defaultCache->get("url$i"));
        }
    }

    public function testMinMaxSize(): void
    {
        // Even with 0 or negative max size, should have at least 1
        $tinyCache = new LruETagCache(0);
        $tinyCache->put("url1", "etag1");
        $this->assertSame(1, $tinyCache->size());

        $tinyCache->put("url2", "etag2");
        $this->assertSame(1, $tinyCache->size());
        $this->assertNull($tinyCache->get("url1")); // Evicted
        $this->assertSame("etag2", $tinyCache->get("url2"));
    }

    public function testGetDoesNotAffectNonExistentKey(): void
    {
        $this->cache->put("url1", "etag1");

        // Get non-existent key should return null without side effects
        $result = $this->cache->get("nonexistent");

        $this->assertNull($result);
        $this->assertSame(1, $this->cache->size());
    }

    public function testMultipleUpdatesToSameKey(): void
    {
        $this->cache->put("url1", "etag1");
        $this->cache->put("url1", "etag2");
        $this->cache->put("url1", "etag3");

        $this->assertSame(1, $this->cache->size());
        $this->assertSame("etag3", $this->cache->get("url1"));
    }

    public function testEvictionOrderWithMixedOperations(): void
    {
        // Add entries
        $this->cache->put("url1", "etag1");
        $this->cache->put("url2", "etag2");
        $this->cache->put("url3", "etag3");

        // Access url1 and url2, update url3
        $this->cache->get("url1");
        $this->cache->get("url2");
        $this->cache->put("url3", "etag3-updated");

        // Add url4 - should evict url1 since url3 was updated most recently
        // Actually url1 was accessed after url3 was added, so url2 should be evicted
        // Wait, let me think about this again:
        // - url1 added (access 1)
        // - url2 added (access 2)
        // - url3 added (access 3)
        // - url1 accessed (access 4)
        // - url2 accessed (access 5)
        // - url3 updated (access 6)
        // LRU is now url1 (access 4)
        $this->cache->put("url4", "etag4");

        $this->assertNull($this->cache->get("url1")); // Evicted
        $this->assertNotNull($this->cache->get("url2"));
        $this->assertNotNull($this->cache->get("url3"));
        $this->assertNotNull($this->cache->get("url4"));
    }
}

