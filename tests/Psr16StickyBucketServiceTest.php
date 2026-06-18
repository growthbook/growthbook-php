<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\Growthbook;
use Growthbook\Psr16StickyBucketService;
use PHPUnit\Framework\TestCase;

final class Psr16StickyBucketServiceTest extends TestCase
{
    /**
     * Build an array-backed PSR-16 cache (signature-agnostic across psr/simple-cache 2.x/3.x).
     */
    private function arrayCache(): \Psr\SimpleCache\CacheInterface
    {
        $store = [];
        $cache = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $cache->method('set')->willReturnCallback(function ($key, $value) use (&$store) {
            $store[$key] = $value;
            return true;
        });
        $cache->method('get')->willReturnCallback(function ($key, $default = null) use (&$store) {
            return $store[$key] ?? $default;
        });
        $cache->method('getMultiple')->willReturnCallback(function ($keys, $default = null) use (&$store) {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $store[$key] ?? $default;
            }
            return $result;
        });
        $cache->method('has')->willReturnCallback(function ($key) use (&$store) {
            return isset($store[$key]);
        });
        $cache->method('delete')->willReturnCallback(function ($key) use (&$store) {
            unset($store[$key]);
            return true;
        });
        return $cache;
    }

    /**
     * @param array<string, string> $assignments
     * @return array<string,mixed>
     */
    private function doc(string $name, string $value, array $assignments): array
    {
        return ['attributeName' => $name, 'attributeValue' => $value, 'assignments' => $assignments];
    }

    public function testSaveAndGetRoundTrip(): void
    {
        $service = new Psr16StickyBucketService($this->arrayCache());

        $service->saveAssignments($this->doc('id', 'user-1', ['exp__0' => '1']));

        $loaded = $service->getAssignments('id', 'user-1');
        $this->assertNotNull($loaded);
        assert(is_array($loaded));
        $this->assertSame('id', $loaded['attributeName']);
        $this->assertSame('user-1', $loaded['attributeValue']);
        $this->assertSame(['exp__0' => '1'], $loaded['assignments']);
    }

    public function testGetReturnsNullForMissing(): void
    {
        $service = new Psr16StickyBucketService($this->arrayCache());
        $this->assertNull($service->getAssignments('id', 'nobody'));
    }

    public function testGetAllAssignmentsBatch(): void
    {
        $service = new Psr16StickyBucketService($this->arrayCache());
        $service->saveAssignments($this->doc('id', 'user-1', ['exp__0' => '1']));
        $service->saveAssignments($this->doc('deviceId', 'd-9', ['exp__0' => '2']));

        $docs = $service->getAllAssignments(['id' => 'user-1', 'deviceId' => 'd-9', 'missing' => 'x']);

        // keyed by "attr||value"; missing attribute is omitted
        $this->assertCount(2, $docs);
        $this->assertSame(['exp__0' => '1'], $docs['id||user-1']['assignments']);
        $this->assertSame(['exp__0' => '2'], $docs['deviceId||d-9']['assignments']);
        $this->assertArrayNotHasKey('missing||x', $docs);
    }

    public function testHandlesReservedCharactersInAttributeValue(): void
    {
        // PSR-16 reserves {}()/\@: in keys; the service hashes the key so these are safe
        $service = new Psr16StickyBucketService($this->arrayCache());
        $value = 'user@example.com/path:1';

        $service->saveAssignments($this->doc('email', $value, ['exp__0' => '1']));

        $loaded = $service->getAssignments('email', $value);
        $this->assertNotNull($loaded);
        assert(is_array($loaded));
        $this->assertSame(['exp__0' => '1'], $loaded['assignments']);
    }

    public function testIntegrationPersistsViaSharedCache(): void
    {
        $cache = $this->arrayCache();
        $features = [
            'exp-feature' => [
                'defaultValue' => 'control',
                'rules' => [[
                    'key' => 'my-exp',
                    'variations' => ['control', 'red', 'blue'],
                    'meta' => [['key' => '0'], ['key' => '1'], ['key' => '2']],
                    'coverage' => 1,
                    'weights' => [0.34, 0.33, 0.33],
                ]],
            ],
        ];

        // First instance evaluates and persists the sticky assignment into the shared cache
        $gb1 = Growthbook::create()
            ->withStickyBucketing(new Psr16StickyBucketService($cache), null)
            ->withFeatures($features)
            ->withAttributes(['id' => 'user-1']);
        $v1 = $gb1->getValue('exp-feature', 'none');

        // The assignment must be persisted and retrievable from the shared cache
        $persisted = (new Psr16StickyBucketService($cache))->getAssignments('id', 'user-1');
        $this->assertNotNull($persisted, 'sticky assignment should be persisted to the PSR-16 cache');
        assert(is_array($persisted));
        $this->assertArrayHasKey('assignments', $persisted);

        // A fresh instance sharing the same cache reads back the same variation
        $gb2 = Growthbook::create()
            ->withStickyBucketing(new Psr16StickyBucketService($cache), null)
            ->withFeatures($features)
            ->withAttributes(['id' => 'user-1']);
        $v2 = $gb2->getValue('exp-feature', 'none');

        $this->assertSame($v1, $v2);
    }
}
