<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\Condition;
use Growthbook\FeatureResult;
use Growthbook\Growthbook;
use Growthbook\InlineExperiment;
use Growthbook\InMemoryStickyBucketService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Psr\SimpleCache\CacheInterface;
use React\Http\Browser;


final class GrowthbookTest extends TestCase
{
    /**
     * @var array<string,array<int,mixed[]>>
     */
    private $cases;

    /**
     * @return array<int|string,mixed[]>
     * @throws Exception
     */
    protected function getCases(string $section, bool $extractName = true): array
    {
        if (!$this->cases) {
            $cases = file_get_contents(__DIR__ . '/cases.json');
            if (!$cases) {
                throw new Exception("Could not load cases.json");
            }
            $this->cases = json_decode($cases, true);
        }

        if (!array_key_exists($section, $this->cases)) {
            throw new Exception("Unknown test case: $section");
        }
        $raw = $this->cases[$section];
        if (!$this->arrayIsList($raw)) {
            return $raw;
        }

        $arr = [];
        foreach ($raw as $row) {
            if ($extractName) {
                $arr[$row[0]] = array_slice($row, 1);
            } else {
                $arr[] = $row;
            }
        }

        return $arr;
    }


    /**
     * @dataProvider getBucketRangeProvider
     * @param array{0:int,1:float,2:null|float[]} $args
     * @param array{0:float,1:float}[] $expected
     */
    public function testGetBucketRange(array $args, array $expected): void
    {
        $actual = Growthbook::getBucketRanges($args[0], $args[1], $args[2]);
        $this->assertSame(count($expected), count($actual));
        foreach ($actual as $k => $v) {
            $this->assertSame(count($expected[$k]), count($v));

            foreach ($v as $i => $n) {
                $this->assertEqualsWithDelta($expected[$k][$i], $n, 0.01);
            }
        }
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function getBucketRangeProvider(): array
    {
        return $this->getCases("getBucketRange");
    }

    /**
     * @dataProvider hashProvider
     */
    public function testHash(string $seed, string $value, int $version, ?float $expected = null): void
    {
        $actual = Growthbook::hash($seed, $value, $version);
        $this->assertSame($actual, $expected);
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function hashProvider(): array
    {
        return $this->getCases("hash", false);
    }

    /**
     * @dataProvider evalConditionProvider
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $attributes
     * @param bool $expected
     */
    public function testEvalCondition(array $condition, array $attributes, bool $expected): void
    {
        $this->assertSame(Condition::evalCondition($attributes, $condition), $expected);
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function evalConditionProvider(): array
    {
        $versionCompare = $this->getCases("versionCompare");
        $versionCases = [];
        foreach ($versionCompare as $comparison => $testCases) {
            foreach ($testCases as $case) {
                $versionCases["versionCompare: " . $case[0] . ' ' . $comparison . ' ' . $case[1]] = [
                    [
                        'v' => [
                            '$v' . $comparison => $case[1]
                        ]
                    ],
                    [
                        'v' => $case[0]
                    ],
                    $case[2]
                ];
            }
        }

        return array_merge($this->getCases("evalCondition"), $versionCases);
    }


    /**
     * @dataProvider getQueryStringOverrideProvider
     *
     */
    public function testGetQueryStringOverride(string $key, string $url, int $numVariations, ?int $expected): void
    {
        $this->assertSame(Growthbook::getQueryStringOverride($key, $url, $numVariations), $expected);
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function getQueryStringOverrideProvider(): array
    {
        return $this->getCases("getQueryStringOverride");
    }


    /**
     * @dataProvider chooseVariationProvider
     * @param float $n
     * @param array{0:float,1:float}[] $ranges
     * @param int $expected
     */
    public function testChooseVariation(float $n, array $ranges, int $expected): void
    {
        $this->assertSame(Growthbook::chooseVariation($n, $ranges), $expected);
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function chooseVariationProvider(): array
    {
        return $this->getCases("chooseVariation");
    }


    /**
     * @dataProvider inNamespaceProvider
     * @param string $id
     * @param array{0:string,1:float,2:float} $namespace
     * @param bool $expected
     */
    public function testInNamespace(string $id, array $namespace, bool $expected): void
    {
        $this->assertSame(Growthbook::inNamespace($id, $namespace), $expected);
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function inNamespaceProvider(): array
    {
        return $this->getCases("inNamespace");
    }


    /**
     * @dataProvider getEqualWeightsProvider
     * @param int $numVariations
     * @param float[] $expected
     */
    public function testGetEqualWeights(int $numVariations, array $expected): void
    {
        $weights = Growthbook::getEqualWeights($numVariations);

        $this->assertSame(array_map(function ($w) {
            return round($w, 8);
        }, $weights), array_map(function ($w) {
            return round($w, 8);
        }, $expected));
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function getEqualWeightsProvider(): array
    {
        return $this->getCases("getEqualWeights", false);
    }


    /**
     * @dataProvider decryptProvider
     * @param string $encryptedString
     * @param string $key
     * @param string|null $expected
     */
    public function testDecrypt(string $encryptedString, string $key, ?string $expected = null): void
    {
        $gb = new Growthbook([
            'decryptionKey' => $key
        ]);

        $actual = null;
        try {
            $actual = $gb->decrypt($encryptedString);
        } catch (Throwable $e) {
            if ($expected) {
                throw $e;
            }
        }

        $this->assertSame($actual, $expected);
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function decryptProvider(): array
    {
        return $this->getCases("decrypt");
    }


    /**
     * @dataProvider featureProvider
     * @param array<string,mixed> $ctx
     * @param string $key
     * @param array<string,mixed> $expected
     */
    public function testFeature(array $ctx, string $key, array $expected): void
    {
        $gb = new Growthbook($ctx);
        $res = $gb->getFeature($key);

        $actual = [
            'value' => $res->value,
            'on' => $res->on,
            'off' => $res->off,
            'source' => $res->source
        ];

        if ($res->experiment) {
            $actual['experiment'] = $this->removeNulls($res->experiment, $expected["experiment"]);
        }
        if ($res->experimentResult) {
            $actual['experimentResult'] = $this->removeNulls($res->experimentResult, $expected["experimentResult"]);
        }

        $this->assertEquals($expected, $actual);
    }

    /**
     * @param mixed $obj
     * @param array<string,mixed> $ref
     * @return array<string,mixed>
     * @throws Exception
     */
    public function removeNulls($obj, ?array $ref): array
    {
        $encoded = json_encode($obj);
        if (!$encoded) {
            throw new Exception("Failed to encode object");
        }
        $arr = json_decode($encoded, true);
        foreach ($arr as $k => $v) {
            if ($v === null || ($k === "active" && $v && !isset($ref['active'])) || ($k === "coverage" && $v === 1 && !isset($ref['coverage'])) || ($k === "hashAttribute" && $v === "id" && !isset($ref['hashAttribute']))) {
                unset($arr[$k]);
            }
        }

        return $arr;
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function featureProvider(): array
    {
        return $this->getCases("feature");
    }


    /**
     * @dataProvider getRunProvider
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $exp
     * @param mixed $expectedValue
     * @param bool $inExperiment
     * @param bool $hashUsed
     */
    public function testRun(array $ctx, array $exp, $expectedValue, bool $inExperiment, bool $hashUsed): void
    {
        $gb = new Growthbook($ctx);
        $experiment = new InlineExperiment($exp["key"], $exp["variations"], $exp);
        $res = $gb->runInlineExperiment($experiment);

        $this->assertSame($res->value, $expectedValue);
        $this->assertSame($res->inExperiment, $inExperiment);
        $this->assertSame($res->hashUsed, $hashUsed);
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function getRunProvider(): array
    {
        return $this->getCases("run");
    }


    public function testFluentInterface(): void
    {
        $attributes = ['id' => 1];
        $callback = function ($exp, $res) {
            // do nothing
        };
        $features = [
            'feature-1' => ['defaultValue' => 1, 'rules' => []]
        ];
        $url = "/home";
        $forcedVariations = ['exp1' => 0];

        $gb = Growthbook::create()
            ->withFeatures($features)
            ->withAttributes($attributes)
            ->withTrackingCallback($callback)
            ->withUrl($url)
            ->withForcedVariations($forcedVariations);

        $this->assertSame($attributes, $gb->getAttributes());
        $this->assertSame($callback, $gb->getTrackingCallback());
        $this->assertSame($url, $gb->getUrl());
        $this->assertSame($forcedVariations, $gb->getForcedVariations());

        $this->assertSame(
            json_encode($features),
            json_encode($gb->getFeatures())
        );
    }

    public function testForcedFeatures(): void
    {
        $gb = Growthbook::create()
            ->withFeatures([
                'feature-1' => ['defaultValue' => false, 'rules' => []]
            ])
            ->withForcedFeatures([
                'feature-1' => new FeatureResult(true, 'forcedFeature')
            ]);

        $this->assertSame(true, $gb->getFeature('feature-1')->value);
    }

    public function testInlineExperiment(): void
    {
        $condition = ['country' => 'US'];
        $weights = [.4, .6];
        $coverage = 0.5;
        $hashAttribute = 'anonId';

        $exp = InlineExperiment::create("my-test", [0, 1])
            ->withCondition($condition)
            ->withWeights($weights)
            ->withCoverage($coverage)
            ->withHashAttribute($hashAttribute)
            ->withNamespace("pricing", 0, 0.5);

        $this->assertSame($condition, $exp->condition);
        $this->assertSame($weights, $exp->weights);
        $this->assertSame($coverage, $exp->coverage);
        $this->assertSame($hashAttribute, $exp->hashAttribute);
        $this->assertSame(['pricing', 0.0, 0.5], $exp->namespace);
    }

    public function testLoggingAndTrackingCallback(): void
    {
        $calls = [];
        $callback = function ($exp, $res) use (&$calls) {
            $calls[] = [$exp, $res];
        };

        $logger = $this->createMock('Psr\Log\AbstractLogger');
        $logger->expects($this->exactly(4))->method("log")->withConsecutive(
            [$this->equalTo("debug"), $this->stringContains("Evaluating feature")],
            [$this->equalTo("debug"), $this->stringContains("Attempting to run experiment")],
            [$this->equalTo("debug"), $this->stringContains("Assigned user a variation")],
            [$this->equalTo("debug"), $this->stringContains("Use feature value from experiment")],
        );

        $gb = Growthbook::create()
            ->withTrackingCallback($callback)
            ->withLogger($logger)
            ->withAttributes(['id' => '1'])
            ->withFeatures([
                'feature' => [
                    'defaultValue' => false,
                    'rules' => [
                        [
                            'variations' => [false, true],
                            'meta' => [
                                ['key' => 'control'],
                                ['key' => 'variation']
                            ]
                        ]
                    ]
                ]
            ]);

        $this->assertSame($calls, []);

        $gb->isOn("feature");

        $this->assertSame(count($calls), 1);
        $this->assertSame($calls[0][0]->key, "feature");
        $this->assertSame($calls[0][1]->key, "variation");
        $this->assertSame($calls[0][1]->variationId, 1);
        $this->assertSame($calls[0][1]->value, true);
        $this->assertSame($calls[0][1]->inExperiment, true);
        $this->assertSame($calls[0][1]->bucket, 0.906);
        $this->assertSame($calls[0][1]->featureId, "feature");
    }

    public function testTrackingCallbackExceptionWithLogger(): void
    {
        $logCalls = [];
        $logger = $this->createMock('Psr\Log\AbstractLogger');
        $logger->method('log')->willReturnCallback(function ($level, $message, $context = []) use (&$logCalls) {
            $logCalls[] = [$level, $message, $context];
        });

        $gb = Growthbook::create()
            ->withLogger($logger)
            ->withAttributes(['id' => 'user123'])
            ->withTrackingCallback(function () {
                throw new Exception("Test exception");
            });

        $exp = new InlineExperiment("test-exp", [0, 1]);
        $gb->runInlineExperiment($exp);

        $foundErrorLog = false;
        foreach ($logCalls as $call) {
            if ($call[0] === 'error' && strpos($call[1], 'Error calling the trackingCallback function') !== false) {
                $foundErrorLog = true;
                break;
            }
        }
        $this->assertTrue($foundErrorLog, 'Expected error log was not found');
    }

    public function testTrackingCallbackExceptionWithoutLogger(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Test exception");

        $gb = Growthbook::create()
            ->withAttributes(['id' => 'user123'])
            ->withTrackingCallback(function () {
                throw new Exception("Test exception");
            });

        $exp = new InlineExperiment("test-exp", [0, 1]);
        $gb->runInlineExperiment($exp);
    }

    public function testLoadFeaturesWithTimeoutSkipCacheStaleWhileRevalidate(): void
    {
        $cache = new class implements CacheInterface {
            /** @var array<string,mixed> */
            private array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                $this->store[$key] = $value;
                return true;
            }

            // delete(string $key): bool
            public function delete(string $key): bool
            {
                unset($this->store[$key]);
                return true;
            }

            // clear(): bool
            public function clear(): bool
            {
                $this->store = [];
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $results = [];
                foreach ($keys as $k) {
                    $results[$k] = $this->store[$k] ?? $default;
                }
                return $results;
            }
            /**
             * @param iterable<string,mixed> $values
             */
            public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
            {
                foreach ($values as $k => $v) {
                    $this->store[$k] = $v;
                }
                return true;
            }

            // deleteMultiple(iterable $keys): bool
            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $k) {
                    unset($this->store[$k]);
                }
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->store[$key]);
            }
        };


        $mockResponseBody = json_encode(["features" => [
            "new-feature" => ["defaultValue" => true],
            "beta-feature" => ["defaultValue" => false]
        ]]);
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getBody')->willReturn($mockResponseBody);

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock->expects($this->any())
            ->method('sendRequest')
            ->willReturn($responseMock);

        $gb = new Growthbook([
            'httpClient' => $httpClientMock,
            'cache' => $cache,
        ]);

        $gb->loadFeatures('client-test', '', '', [
            'timeout' => 5,
            'skipCache' => false,
            'staleWhileRevalidate' => true
        ]);
        $this->assertTrue($gb->isOn('new-feature'), 'Expect new-feature => true from fresh API call');
        $cacheKey = md5("https://cdn.growthbook.io/api/features/client-test");
        $this->assertNotNull($cache->get($cacheKey), 'Features must be stored in cache');

        $responseMock2 = $this->createMock(ResponseInterface::class);
        $responseMock2->method('getBody')->willReturn(json_encode(["features" => [
            "some-other-feature" => ["defaultValue" => false]
        ]]));
        $httpClientMock2 = $this->createMock(ClientInterface::class);
        $httpClientMock2->expects($this->never())
            ->method('sendRequest');

        $gb->withHttpClient($httpClientMock2, $gb->requestFactory);

        $gb->loadFeatures('client-test', '', '', [
            'timeout' => 5,
            'skipCache' => false,
            'staleWhileRevalidate' => true
        ]);

        $this->assertTrue($gb->isOn('new-feature'), 'new-feature still true from cache');

        $responseMock3 = $this->createMock(ResponseInterface::class);
        $responseMock3->method('getBody')->willReturn(json_encode(["features" => [
            "skipcache-feature" => ["defaultValue" => 123]
        ]]));
        $httpClientMock3 = $this->createMock(ClientInterface::class);
        $httpClientMock3->expects($this->once()) // один виклик
        ->method('sendRequest')
            ->willReturn($responseMock3);

        $gb->withHttpClient($httpClientMock3, $gb->requestFactory);

        $gb->loadFeatures('client-test', '', '', [
            'timeout' => 5,
            'skipCache' => true,
            'staleWhileRevalidate' => false
        ]);
        $this->assertSame(123, $gb->getValue('skipcache-feature', null));

        $cache->set($cacheKey . '_time', time() - 9999);

        $responseMock4 = $this->createMock(ResponseInterface::class);
        $responseMock4->method('getBody')->willReturn(json_encode(["features" => [
            "fresh-feature" => ["defaultValue" => "fresh"]
        ]]));
        $httpClientMock4 = $this->createMock(ClientInterface::class);
        $httpClientMock4->expects($this->once())
            ->method('sendRequest')
            ->willReturn($responseMock4);

        $gb->withHttpClient($httpClientMock4, $gb->requestFactory);

        $gb->loadFeatures('client-test', '', '', [
            'timeout' => 5,
            'skipCache' => false,
            'staleWhileRevalidate' => false
        ]);
        $this->assertSame("fresh", $gb->getValue('fresh-feature', null));

        $this->assertTrue(true, 'No exceptions thrown => logic is ok for skipCache, staleWhileRevalidate, and timeout.');
    }


    public function testLoadFeaturesAsyncOption(): void
    {
        $loop = Loop::get();
        $httpClient = new Browser($loop);
        $gb = new Growthbook([
            'loop' => $loop,
            'httpClient' => $httpClient, // Or ->setClient($httpClient) if that’s your library’s pattern
        ]);
        $gb->loadFeatures('demo', '', '', [
            'async' => true,
            'skipCache' => true,
            'timeout' => 3,
        ]);
        $this->assertInstanceOf(PromiseInterface::class, $gb->promise);

        if ($gb->promise instanceof PromiseInterface) {
            $gb->promise->then(
                function (array $features) {
                    $this->assertIsArray($features, "Async features must be an array");
                },
                function (Throwable $e) {
                    $this->fail("Async fetch failed: " . $e->getMessage());
                }
            );
        }
        $loop->run();

        $this->assertTrue(true, "Async loadFeatures completed successfully");
    }
    public function testLoadFeaturesSyncOption(): void
    {
        $loop = Loop::get();
        $httpClient = $this->createMock(ClientInterface::class);
        $gb = new Growthbook([
            'loop' => $loop,
            'httpClient' => $httpClient,
        ]);
        $gb->loadFeatures('demo', '', '', [
            'async' => false,
            'skipCache' => true,
            'timeout' => 3,
        ]);

        $features = $gb->getFeatures();
        $this->assertIsArray($features, "Sync features must be an array");

        $this->assertTrue(true, "Sync loadFeatures completed successfully");
    }
    public function testLoadFeaturesWithCache(): void
    {
        $loop = Loop::get();
        $httpClient = $this->createMock(ClientInterface::class);
        $cache = $this->createMock(CacheInterface::class);
//        $cache->method('get')->willReturn(json_encode(['feature1' => ['on' => true]]));
        $cache->method('get')->willReturn(json_encode( ['feature1' => ['defaultValue' => ['on' => true], 'rules' => []]]));
        $cache->method('set')->willReturn(true);

        $gb = new Growthbook([
            'loop' => $loop,
            'httpClient' => $httpClient,
            'cache' => $cache,
        ]);
        $gb->loadFeatures('demo', '', '', [
            'async' => false,
            'skipCache' => false,
            'timeout' => 3,
        ]);

        $features = $gb->getFeatures();
        $this->assertIsArray($features, "Features must be an array");
        $this->assertArrayHasKey('feature1', $features, "Features must contain 'feature1'");
        $this->assertTrue($features['feature1']->defaultValue['on'], "Feature 'feature1' must be on");

        $this->assertTrue(true, "Load features with cache completed successfully");
    }

    public function testLoadFeaturesWithInvalidClientKey(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Must specify a clientKey before loading features.");

        $loop = Loop::get();
        $httpClient = new Browser($loop);
        $gb = new Growthbook([
            'loop' => $loop,
            'httpClient' => $httpClient,
        ]);
        $gb->loadFeatures('', '', '', [
            'async' => false,
            'skipCache' => true,
            'timeout' => 3,
        ]);
    }

    public function testLoadFeaturesWithWrongHttpClient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("httpClient must be an instance of ClientInterface or Browser");

        $loop = Loop::get();
        // @phpstan-ignore-next-line
        $gb = new Growthbook([
            'loop' => $loop,
            'httpClient' => 'wrong'
        ]);
        $gb->loadFeatures('demo', '', '', [
            'async' => false,
            'skipCache' => true,
            'timeout' => 3,
        ]);
    }

    public function testLoadFeaturesStaleWhileRevalidateAsync(): void
    {
        // Create a ReactPHP event loop
        $loop = Loop::get();

        // Simulate cache
        $cache = $this->createMock(CacheInterface::class);

        // Create stale features with defaultValue = true
        $staleFeatures = [
            'feature-stale' => [
                'defaultValue' => true,
                'rules' => []
            ]
        ];
        $encoded = json_encode($staleFeatures);

        // Assume the cache key is md5("https://cdn.growthbook.io/api/features/clientKey")
        // Ensure the real code uses this exact key.
        $url = "https://cdn.growthbook.io/api/features/clientKey";
        $cacheKey = md5($url);

        // Cache returns stale features and old timestamp
        $cache->method('get')->willReturnMap([
            [$cacheKey, null, $encoded],
            [$cacheKey.'_time', null, time() - 120],
        ]);

        // Simulate an asynchronous client
        $asyncClient = $this->createMock(Browser::class);

        // New features after update:
        $updatedFeatures = ['feature-stale' => ['defaultValue' => false, 'rules' => []]];
        $responseBody = json_encode(['features' => $updatedFeatures]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($responseBody);

        $deferred = new Deferred();
        $deferred->resolve($response);

        $asyncClient->method('get')->willReturn($deferred->promise());

        // Create Growthbook
        $gb = new Growthbook([
            'cache' => $cache,
            'loop' => $loop,
            'httpClient' => $this->createMock(ClientInterface::class),
            'requestFactory' => $this->createMock(RequestFactoryInterface::class),
        ]);

        // Set asyncClient
        $refProperty = new \ReflectionProperty($gb, 'asyncClient');
        $refProperty->setAccessible(true);
        $refProperty->setValue($gb, $asyncClient);

        // Load features
        $gb->loadFeatures('clientKey', 'https://cdn.growthbook.io', '', [
            'staleWhileRevalidate' => true
        ]);

        // Verify features were set
        // Add a debug assert:
        $features = $gb->getFeatures();
        $this->assertArrayHasKey('feature-stale', $features, "Stale feature should be set from cache");
        $this->assertTrue($features['feature-stale']->defaultValue, "defaultValue should be true for stale feature");

        // Now check isOn()
        $this->assertTrue($gb->isOn('feature-stale'), "Should return stale cached features immediately");

        // Run event loop for background update
        $loop->run();

        // After update, features should become false
        $this->assertFalse($gb->isOn('feature-stale'), "Should have revalidated and updated features in background");
    }
    public function testLoadFeaturesWithWrongRequestFactoryClient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("requestFactory must be an instance of RequestFactoryInterface");

        $loop = Loop::get();
        // @phpstan-ignore-next-line
        $gb = new Growthbook([
            'loop' => $loop,
            'requestFactory' => 'wrong',
        ]);
        $gb->loadFeatures('demo', '', '', [
            'async' => false,
            'skipCache' => true,
            'timeout' => 3,
        ]);
    }
    public function testLoadFeaturesSkipCacheAsync(): void
    {
        $loop = Loop::get();

        $cache = $this->createMock(CacheInterface::class);
        $cachedFeatures = ['featureA' => ['defaultValue' => true, 'rules' => []]];
        $encoded = json_encode($cachedFeatures);

        $cache->method('get')->willReturnMap([
            ['6c9e3071f693aae0dc874ebc8c36bd77', null, $encoded],
            ['6c9e3071f693aae0dc874ebc8c36bd77_time', null, time()]
        ]);

        $asyncClient = $this->createMock(Browser::class);
        $updatedFeatures = ['featureA' => ['defaultValue' => false, 'rules' => []]];
        $responseBody = json_encode(['features' => $updatedFeatures]);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($responseBody);

        $deferred = new Deferred();
        $deferred->resolve($response);

        $asyncClient->method('get')->willReturn($deferred->promise());

        $gb = new Growthbook([
            'cache' => $cache,
            'loop' => $loop,
            'httpClient' => $this->createMock(ClientInterface::class),
            'requestFactory' => $this->createMock(RequestFactoryInterface::class),
        ]);

        $refProperty = new \ReflectionProperty($gb, 'asyncClient');
        $refProperty->setAccessible(true);
        $refProperty->setValue($gb, $asyncClient);

        $gb->loadFeatures('clientKey', 'https://cdn.growthbook.io', '', [
            'skipCache' => true,
            'async' => true
        ]);

        // Before running the loop, features are not loaded from API, so it should be false
        $this->assertFalse($gb->isOn('featureA'), "Initially no features loaded since async not run");

        $loop->run();

        // After running the event loop, features should be updated from the API
        $this->assertFalse($gb->isOn('featureA'), "After running event loop, should fetch from API updating feature to false");
    }
    public function testLoadFeaturesTimeoutAsync(): void
    {
        $loop = Loop::get();

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);

        $asyncClient = $this->createMock(Browser::class);

        // Simulate a timeout or error when fetching features
        $deferred = new Deferred();
        $deferred->reject(new \Exception("Timeout exceeded"));

        $asyncClient->method('get')->willReturn($deferred->promise());

        $gb = new Growthbook([
            'cache' => $cache,
            'loop' => $loop,
            'httpClient' => $this->createMock(ClientInterface::class),
            'requestFactory' => $this->createMock(RequestFactoryInterface::class),
        ]);

        $refProperty = new \ReflectionProperty($gb, 'asyncClient');
        $refProperty->setAccessible(true);
        $refProperty->setValue($gb, $asyncClient);

        // Set timeout
        $gb->loadFeatures('clientKey', 'https://cdn.growthbook.io', '', ['timeout' => 1]);

        // Before run(), no features are loaded since nothing is fetched
        $this->assertNull($gb->getFeature('non-existent')->value, "No features loaded yet");

        // Run event loop - promise will fail
        $loop->run();

        // Even after run(), update failed due to timeout
        $this->assertNull($gb->getFeature('non-existent')->value, "No features should be loaded after timeout error");
    }
    public function testTimerLogicForBackgroundRevalidation(): void
    {
        $loop = Loop::get();

        // Simulate cache with stale features
        $cache = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $staleFeatures = ['old-feature' => ['defaultValue' => true]];
        $encoded = json_encode($staleFeatures);

        $url = "https://cdn.growthbook.io/api/features/clientKey";
        $cacheKey = md5($url);

        // Returns stale features and old timestamp
        $cache->method('get')->willReturnMap([
            [$cacheKey, null, $encoded],
            [$cacheKey.'_time', null, time() - 120], // stale
        ]);

        // Mock async client that will return updated features after the timer
        $asyncClient = $this->createMock(\React\Http\Browser::class);
        $updatedFeatures = ['old-feature' => ['defaultValue' => false]];
        $responseBody = json_encode(['features' => $updatedFeatures]);
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($responseBody);

        $deferred = new Deferred();
        $deferred->resolve($response);
        $asyncClient->method('get')->willReturn($deferred->promise());

        $gb = new Growthbook([
            'cache' => $cache,
            'loop' => $loop,
            'httpClient' => $this->createMock(ClientInterface::class),
            'requestFactory' => $this->createMock(RequestFactoryInterface::class),
        ]);

        // Inject the asyncClient mock
        $refProperty = new \ReflectionProperty($gb, 'asyncClient');
        $refProperty->setAccessible(true);
        $refProperty->setValue($gb, $asyncClient);

        // Load stale features from cache first
        $gb->loadFeatures('clientKey','https://cdn.growthbook.io','', [
            'staleWhileRevalidate' => true
        ]);

        // Assert we have stale data before the timer runs
        $this->assertTrue($gb->isOn('old-feature'), "Initially uses stale cached features");

        // Now let's explicitly call the background revalidation logic

        $this->assertTrue($gb->isOn('old-feature'), "Still stale before loop run");

        // Run the event loop, allowing the timer to fire and fetch new features
        $loop->run();

        // After running the loop, the timer should have triggered async fetch & update
        $this->assertFalse($gb->isOn('old-feature'), "Features updated after timer fired and async fetch completed");
    }
    public function testLoadFeaturesWithValidCache(): void
    {
        $clientKey = 'testClientKey';
        $apiHost = 'https://api.example.com';
        $cacheKey = md5(rtrim($apiHost, "/") . "/api/features/" . $clientKey);

        $features = ['feature1' => ['defaultValue' => true, 'rules' => []]];

        $cacheMock = $this->createMock(CacheInterface::class);
        $cacheMock->expects($this->exactly(2))
            ->method('get')
            ->withConsecutive(
                [$cacheKey, null],
                [$cacheKey . '_time', null]
            )
            ->willReturnOnConsecutiveCalls(
                json_encode($features),
                time()
            );

        $gb = Growthbook::create()
            ->withCache($cacheMock)
            ->withLogger($this->createMock(LoggerInterface::class));

        $gb->loadFeatures($clientKey, $apiHost);

        $actualFeatures = [];
        foreach ($gb->getFeatures() as $key => $feature) {
            $actualFeatures[$key] = [
                'defaultValue' => $feature->defaultValue,
                'rules' => $feature->rules
            ];
        }

        $this->assertEquals($features, $actualFeatures);
    }

    public function testLoadFeaturesWithoutCredentialsButWithValidCache(): void
    {
        $clientKey = 'apiKey';
        $apiHost = '';
        $cacheKey = md5(rtrim("https://cdn.growthbook.io", "/") . "/api/features/" . $clientKey);

        // Added 'rules' => []
        $features = ['feature1' => ['defaultValue' => true, 'rules' => []]];

        $cacheMock = $this->createMock(CacheInterface::class);
        $cacheMock->expects($this->exactly(2))
            ->method('get')
            ->withConsecutive(
                [$cacheKey, null],
                [$cacheKey . '_time', null]
            )
            ->willReturnOnConsecutiveCalls(
                json_encode($features),
                time()
            );
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('log')
            ->with(
                $this->equalTo(LogLevel::INFO),
                $this->stringContains('Load features from cache'),
                $this->arrayHasKey('url')
            );

        $gb = Growthbook::create()
            ->withCache($cacheMock)
            ->withLogger($loggerMock);

        $gb->loadFeatures($clientKey, $apiHost, '', ['timeout' => 0, 'skipCache' => false, 'staleWhileRevalidate' => true, 'async' => false]);

        // Convert Feature objects to arrays for comparison
        $actualFeatures = [];
        foreach ($gb->getFeatures() as $key => $feature) {
            $actualFeatures[$key] = [
                'defaultValue' => $feature->defaultValue,
                'rules' => $feature->rules
            ];
        }

        $this->assertEquals($features, $actualFeatures);
    }
    public function testLoadFeaturesWithoutCacheAndMissingCredentials(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Must specify a clientKey before loading features.");

        $gb = Growthbook::create();

        $gb->loadFeatures();
        $this->assertEmpty($gb->getFeatures());
    }

    public function testLoadFeaturesWithConnectionIssueAndValidCache(): void
    {
        $clientKey = 'testClientKey';
        $apiHost = 'https://api.example.com';
        $cacheKey = md5(rtrim($apiHost, "/") . "/api/features/" . $clientKey);

        $features = ['feature1' => ['defaultValue' => true, 'rules' => []]];

        // Adjust cached data to match the expected format
        $cachedData = $features; // Store without 'features' key

        $cacheMock = $this->createMock(CacheInterface::class);
        $cacheMock->expects($this->exactly(3))
            ->method('get')
            ->withConsecutive(
                [$cacheKey],
                [$cacheKey.'_time'],
                [$cacheKey]
            )
            ->willReturnOnConsecutiveCalls(
                null, time() - 120, // First attempt - cache missing or expired
                json_encode($cachedData) // Second attempt - cache with data (possibly stale)
            );


        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock->expects($this->once())
            ->method('sendRequest')
            ->willThrowException(new \Exception("Connection error"));

        $requestFactoryMock = $this->createMock(RequestFactoryInterface::class);
        $requestFactoryMock->expects($this->once())
            ->method('createRequest')
            ->with('GET', rtrim($apiHost, "/") . "/api/features/" . $clientKey)
            ->willReturn($this->createMock(RequestInterface::class));

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->exactly(2))
            ->method('log')
            ->withConsecutive(
                [
                    $this->equalTo(LogLevel::ERROR), // Expect 'error' level
                    $this->stringContains('Exception while loading features from API'),
                    $this->arrayHasKey('exception')
                ],
                [
                    $this->equalTo(LogLevel::WARNING),
                    $this->stringContains('Using possibly stale features from cache due to exception'),
                    $this->arrayHasKey('url')
                ]
            );

        $gb = Growthbook::create()
            ->withCache($cacheMock)
            ->withHttpClient($httpClientMock, $requestFactoryMock)
            ->withLogger($loggerMock);

        $gb->loadFeatures($clientKey, $apiHost);

        // Convert Feature objects to arrays for comparison
        $actualFeatures = [];
        foreach ($gb->getFeatures() as $key => $feature) {
            $actualFeatures[$key] = [
                'defaultValue' => $feature->defaultValue,
                'rules' => $feature->rules
            ];
        }

        $this->assertEquals($features, $actualFeatures);
    }
    public function testLoadFeaturesWithConnectionIssueAndNoCache(): void
    {
        $clientKey = 'testClientKey';
        $apiHost = 'https://api.example.com';

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock->expects($this->once())
            ->method('sendRequest')
            ->willThrowException(new \Exception("Connection error"));

        $requestFactoryMock = $this->createMock(RequestFactoryInterface::class);
        $requestFactoryMock->expects($this->once())
            ->method('createRequest')
            ->with('GET', rtrim($apiHost, "/") . "/api/features/" . $clientKey)
            ->willReturn($this->createMock(RequestInterface::class));

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('log')
            ->with(
                $this->equalTo(LogLevel::ERROR),
                $this->stringContains('Exception while loading features from API'),
                $this->arrayHasKey('exception')
            );

        $gb = Growthbook::create()
            ->withHttpClient($httpClientMock, $requestFactoryMock)
            ->withLogger($loggerMock);

        $gb->loadFeatures($clientKey, $apiHost, '', ['async' => false]);

        $this->assertEmpty($gb->getFeatures());
    }

    /**
     * Returns true if the array is JSON array instead of object
     * @param array<int,mixed> $arr
     */
    protected function arrayIsList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }


    /**
     * @dataProvider getStickyBucketProvider
     * @param array<string,mixed> $ctx
     * @param array<array> $docs
     * @param string $key
     * @param array<string, mixed>|null $expectedResult
     * @param array<string, array> $expectedDocs
     * @throws Exception
     */
    public function testStickyBucket(array $ctx, array $docs, string $key, ?array $expectedResult, array $expectedDocs): void
    {
        $service = new InMemoryStickyBucketService();

        foreach ($docs as $doc) {
            $service->saveAssignments(['attributeName' => $doc['attributeName'], 'attributeValue' => $doc['attributeValue'], 'assignments' => $doc['assignments']]);
        }

        $ctx['stickyBucketService'] = $service;

        if (array_key_exists('stickyBucketAssignmentDocs', $ctx)) {
            $service->docs = $ctx['stickyBucketAssignmentDocs'];
            unset($ctx['stickyBucketAssignmentDocs']);
        }

        $gb = new Growthbook($ctx);

        $res = $gb->getFeature($key);

        if (!$res->experimentResult) {
            $this->assertNull($expectedResult);
        } else {
            $this->assertEquals($expectedResult, $this->removeNulls($res->experimentResult, $expectedResult));
        }

        foreach ($expectedDocs as $key => $value) {
            $this->assertEquals($service->docs[$key], $value);
        }

        $service->destroy();
    }

    /**
     * @return array<int|string,mixed[]>
     * @throws Exception
     */
    public function getStickyBucketProvider(): array
    {
        return $this->getCases("stickyBucket");
    }

    /**
     * @return void
     */
    public function testStickyBucketService(): void
    {
        $features = [
            "feature" => [
                "defaultValue" => 5,
                "rules" => [[
                    "key" => "exp",
                    "variations" => [0, 1],
                    "weights" => [0, 1],
                    "meta" => [
                        ["key" => "control"],
                        ["key" => "variation1"]
                    ]
                ]]
            ],
        ];

        $service = new InMemoryStickyBucketService();

        $gb = new Growthbook(
            [
                'stickyBucketService' => $service,
                'attributes' => ['id' => 1],
                'features' => $features
            ]
        );

        $this->assertEquals(1, $gb->getFeature('feature')->value);
        $this->assertEquals(
            ['attributeName' => 'id', 'attributeValue' => 1, 'assignments' => ['exp__0' => 'variation1']],
            $service->getAssignments('id', 1)
        );

        $features['feature']['rules'][0]['weights'] = [1, 0];
        $gb->withFeatures($features);
        $this->assertEquals(1, $gb->getFeature('feature')->value);

        //New GrowthBook instance should also get variation
        $gb2 = new Growthbook(
            [
                'stickyBucketService' => $service,
                'attributes' => ['id' => 1],
                'features' => $features
            ]
        );
        $this->assertEquals(1, $gb2->getFeature('feature')->value);

        //New users should get control
        $gb->withAttributes(['id' => 2]);
        $this->assertEquals(0, $gb->getFeature('feature')->value);

        //Bumping bucketVersion, should reset sticky buckets
        $gb->withAttributes(['id' => 1]);
        $features["feature"]["rules"][0]["bucketVersion"] = 1;
        $gb->withFeatures($features);
        $this->assertEquals(0, $gb->getFeature('feature')->value);

        $this->assertEquals(
            ['attributeName' => 'id', 'attributeValue' => 1, 'assignments' => ['exp__0' => 'variation1',
                "exp__1" => "control"]],
            $service->getAssignments('id', 1)
        );
    }
}
