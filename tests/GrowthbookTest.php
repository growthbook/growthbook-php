<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\Condition;
use Growthbook\FeatureResult;
use Growthbook\Growthbook;
use Growthbook\InlineExperiment;
use Growthbook\InMemoryStickyBucketService;
use PHPUnit\Framework\TestCase;

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
                throw new \Exception("Could not load cases.json");
            }
            $this->cases = json_decode($cases, true);
        }

        if (!array_key_exists($section, $this->cases)) {
            throw new \Exception("Unknown test case: $section");
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
     * @param array<string,mixed> $savedGroups
     */
    public function testEvalCondition(array $condition, array $attributes, bool $expected, array $savedGroups = []): void
    {
        $this->assertSame(Condition::evalCondition($attributes, $condition, $savedGroups), $expected);
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function evalConditionProvider(): array
    {
        return $this->getCases("evalCondition");
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
        } catch (\Throwable $e) {
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
            'source' => $res->source,
            'ruleId' => $res->ruleId,
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
            throw new \Exception("Failed to encode object");
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
            ['attributeName' => 'id', 'attributeValue' => 1, 'assignments' => [
                'exp__0' => 'variation1',
                "exp__1" => "control"
            ]],
            $service->getAssignments('id', 1)
        );
    }

    /**
     * @return void
     */
    public function testStickyBucketZeroVariationKey(): void
    {
        $features = [
            "feature" => [
                "defaultValue" => 5,
                "rules" => [[
                    "key" => "exp",
                    "variations" => [0, 1],
                    "weights" => [0, 1],
                    "meta" => [
                        ["key" => "0"],
                        ["key" => "1"]
                    ]
                ]]
            ],
        ];

        $service = new InMemoryStickyBucketService();
        $service->saveAssignments([
            'attributeName' => 'id',
            'attributeValue' => 1,
            'assignments' => ['exp__0' => '0']
        ]);

        $gb = new Growthbook(
            [
                'stickyBucketService' => $service,
                'attributes' => ['id' => 1],
                'features' => $features
            ]
        );

        $this->assertEquals(0, $gb->getFeature('feature')->value);

        $service->destroy();
    }

    // ========== SET* METHODS TEST CASES ==========

    /**
     * @return void
     */
    public function testSetAttributes(): void
    {
        $gb = new Growthbook();
        $attributes = ['id' => 123, 'country' => 'US'];

        $result = $gb->setAttributes($attributes);

        $this->assertSame($gb, $result);
        $this->assertSame($attributes, $gb->getAttributes());
    }

    /**
     * @return void
     */
    public function testSetAttributesRefreshesStickyBuckets(): void
    {
        $service = $this->createMock(InMemoryStickyBucketService::class);
        $service->expects($this->once())
            ->method('getAllAssignments')
            ->willReturn([]);

        $gb = new Growthbook(['stickyBucketService' => $service]);
        $gb->setAttributes(['id' => 'test']);
    }

    /**
     * @return void
     */
    public function testSetSavedGroups(): void
    {
        $gb = new Growthbook();
        $savedGroups = ['group1' => ['param' => 'value']];

        $result = $gb->setSavedGroups($savedGroups);

        $this->assertSame($gb, $result);
        $this->assertSame($savedGroups, $gb->getSavedGroups());
    }

    /**
     * @return void
     */
    public function testSetTrackingCallback(): void
    {
        $gb = new Growthbook();
        $callback = function ($exp, $res) {
            // Test callback
        };

        $result = $gb->setTrackingCallback($callback);

        $this->assertSame($gb, $result);
        $this->assertSame($callback, $gb->getTrackingCallback());
    }

    /**
     * @return void
     */
    public function testSetTrackingCallbackToNull(): void
    {
        $gb = new Growthbook();
        $gb->setTrackingCallback(function () {});

        $result = $gb->setTrackingCallback(null);

        $this->assertSame($gb, $result);
        $this->assertNull($gb->getTrackingCallback());
    }

    /**
     * @return void
     */
    public function testSetFeatures(): void
    {
        $gb = new Growthbook();
        $features = [
            'feature1' => ['defaultValue' => true],
            'feature2' => new \Growthbook\Feature(['defaultValue' => false])
        ];

        $result = $gb->setFeatures($features);

        $this->assertSame($gb, $result);
        $actualFeatures = $gb->getFeatures();

        $this->assertCount(2, $actualFeatures);
        $this->assertTrue($actualFeatures['feature1']->defaultValue);
        $this->assertFalse($actualFeatures['feature2']->defaultValue);
    }

    /**
     * @return void
     */
    public function testSetFeaturesRefreshesStickyBuckets(): void
    {
        $service = $this->createMock(InMemoryStickyBucketService::class);
        $service->expects($this->once())
            ->method('getAllAssignments')
            ->willReturn([]);

        $gb = new Growthbook(['stickyBucketService' => $service]);
        $gb->setFeatures(['test' => ['defaultValue' => true]]);
    }

    /**
     * @return void
     */
    public function testSetForcedVariations(): void
    {
        $gb = new Growthbook();
        $forcedVariations = ['exp1' => 1, 'exp2' => 0];

        $result = $gb->setForcedVariations($forcedVariations);

        $this->assertSame($gb, $result);
        $this->assertSame($forcedVariations, $gb->getForcedVariations());
    }

    /**
     * @return void
     */
    public function testSetForcedFeatures(): void
    {
        $gb = new Growthbook();
        $forcedFeatures = [
            'feature1' => new FeatureResult(true, 'forcedFeature'),
            'feature2' => new FeatureResult('custom', 'forcedFeature')
        ];

        $result = $gb->setForcedFeatures($forcedFeatures);

        $this->assertSame($gb, $result);
        $this->assertSame($forcedFeatures, $gb->getForcedFeatures());
    }

    /**
     * @return void
     */
    public function testSetUrl(): void
    {
        $gb = new Growthbook();
        $url = '/test/page?param=value';

        $result = $gb->setUrl($url);

        $this->assertSame($gb, $result);
        $this->assertSame($url, $gb->getUrl());
    }

    /**
     * @return void
     */
    public function testSetLogger(): void
    {
        $gb = new Growthbook();
        $logger = $this->createMock('Psr\Log\LoggerInterface');

        $result = $gb->setLogger($logger);

        $this->assertSame($gb, $result);
        $this->assertSame($logger, $gb->logger);
    }

    /**
     * @return void
     */
    public function testSetLoggerToNull(): void
    {
        $gb = new Growthbook();
        $logger = $this->createMock('Psr\Log\LoggerInterface');
        $gb->setLogger($logger);

        $result = $gb->setLogger(null);

        $this->assertSame($gb, $result);
        $this->assertNull($gb->logger);
    }

    /**
     * @return void
     */
    public function testSetHttpClient(): void
    {
        $gb = new Growthbook();
        $client = $this->createMock('Psr\Http\Client\ClientInterface');
        $requestFactory = $this->createMock('Psr\Http\Message\RequestFactoryInterface');

        $result = $gb->setHttpClient($client, $requestFactory);

        $this->assertSame($gb, $result);
        $this->assertSame($client, $gb->httpClient);
        $this->assertSame($requestFactory, $gb->requestFactory);
    }

    /**
     * @return void
     */
    public function testSetCache(): void
    {
        $gb = new Growthbook();
        $cache = $this->createMock('Psr\SimpleCache\CacheInterface');

        $result = $gb->setCache($cache);

        $this->assertSame($gb, $result);
        $this->assertSame($cache, $gb->cache);
        $this->assertSame(60, $gb->cacheTTL); // Default TTL
    }

    /**
     * @return void
     */
    public function testSetCacheWithCustomTTL(): void
    {
        $gb = new Growthbook();
        $cache = $this->createMock('Psr\SimpleCache\CacheInterface');
        $customTTL = 120;

        $result = $gb->setCache($cache, $customTTL);

        $this->assertSame($gb, $result);
        $this->assertSame($cache, $gb->cache);
        $this->assertSame($customTTL, $gb->cacheTTL);
    }

    /**
     * @return void
     */
    public function testSetStickyBucketing(): void
    {
        $gb = new Growthbook();
        $service = $this->createMock(InMemoryStickyBucketService::class);
        $attributes = ['id', 'email'];

        $result = $gb->setStickyBucketing($service, $attributes);

        $this->assertSame($gb, $result);
        $this->assertSame($service, $gb->stickyBucketService);
        $this->assertSame($attributes, $gb->stickyBucketIdentifierAttributes);
        $this->assertFalse($gb->usingDerivedStickyBucketAttributes);
    }

    /**
     * @return void
     */
    public function testSetStickyBucketingWithNullAttributes(): void
    {
        $gb = new Growthbook();
        $service = $this->createMock(InMemoryStickyBucketService::class);

        $result = $gb->setStickyBucketing($service, null);

        $this->assertSame($gb, $result);
        $this->assertSame($service, $gb->stickyBucketService);
        $this->assertNull($gb->stickyBucketIdentifierAttributes);
        $this->assertTrue($gb->usingDerivedStickyBucketAttributes);
    }

    /**
     * @return void
     */
    public function testSetStickyBucketingRefreshesStickyBuckets(): void
    {
        $service = $this->createMock(InMemoryStickyBucketService::class);
        $service->expects($this->once())
            ->method('getAllAssignments')
            ->willReturn([]);

        $gb = new Growthbook(['stickyBucketService' => $service]);
        $gb->setStickyBucketing($service, ['id']);
    }

    // ========== UPDATED WITH* METHODS TEST CASES ==========

    /**
     * @return void
     */
    public function testFluentInterface(): void
    {
        $attributes = ['id' => 1, 'country' => 'US'];
        $savedGroups = ['group1' => ['param' => 'value']];
        $callback = function ($exp, $res) {
            // do nothing
        };
        $features = [
            'feature-1' => ['defaultValue' => 1, 'rules' => []],
            'feature-2' => new \Growthbook\Feature(['defaultValue' => 2])
        ];
        $url = "/home";
        $forcedVariations = ['exp1' => 0];
        $forcedFeatures = ['feature-3' => new FeatureResult(true, 'forcedFeature')];
        $logger = $this->createMock('Psr\Log\LoggerInterface');
        $cache = $this->createMock('Psr\SimpleCache\CacheInterface');
        $client = $this->createMock('Psr\Http\Client\ClientInterface');
        $requestFactory = $this->createMock('Psr\Http\Message\RequestFactoryInterface');
        $stickyBucketService = $this->createMock(InMemoryStickyBucketService::class);
        $stickyBucketIdentifierAttributes = ['id', 'email'];

        $gb = Growthbook::create()
            ->withFeatures($features)
            ->withAttributes($attributes)
            ->withSavedGroups($savedGroups)
            ->withTrackingCallback($callback)
            ->withUrl($url)
            ->withForcedVariations($forcedVariations)
            ->withForcedFeatures($forcedFeatures)
            ->withLogger($logger)
            ->withCache($cache, 120)
            ->withHttpClient($client, $requestFactory)
            ->withStickyBucketing($stickyBucketService, $stickyBucketIdentifierAttributes);

        // Test that all properties are set correctly
        $this->assertSame($attributes, $gb->getAttributes());
        $this->assertSame($savedGroups, $gb->getSavedGroups());
        $this->assertSame($callback, $gb->getTrackingCallback());
        $this->assertSame($url, $gb->getUrl());
        $this->assertSame($forcedVariations, $gb->getForcedVariations());
        $this->assertSame($forcedFeatures, $gb->getForcedFeatures());
        $this->assertSame($logger, $gb->logger);
        $this->assertSame($cache, $gb->cache);
        $this->assertSame(120, $gb->cacheTTL);
        $this->assertSame($client, $gb->httpClient);
        $this->assertSame($requestFactory, $gb->requestFactory);
        $this->assertSame($stickyBucketService, $gb->stickyBucketService);
        $this->assertSame($stickyBucketIdentifierAttributes, $gb->stickyBucketIdentifierAttributes);

        // Test features
        $actualFeatures = $gb->getFeatures();
        $this->assertCount(2, $actualFeatures);
        $this->assertSame(1, $actualFeatures['feature-1']->defaultValue);
        $this->assertSame(2, $actualFeatures['feature-2']->defaultValue);

        // Test that with* methods return a new instance (immutability)
        $newAttributes = ['id' => 999];
        $newGb = $gb->withAttributes($newAttributes);

        $this->assertNotSame($gb, $newGb); // Should be different instances
        $this->assertSame($attributes, $gb->getAttributes()); // Original unchanged
        $this->assertSame($newAttributes, $newGb->getAttributes()); // New has updated value
    }

    /**
     * @return void
     */
    public function testWithMethodsImmutability(): void
    {
        $originalGb = Growthbook::create()
            ->withAttributes(['id' => 1])
            ->withFeatures(['test' => ['defaultValue' => true]])
            ->withUrl('/original');

        // Test each with* method creates a new instance
        $newGb1 = $originalGb->withAttributes(['id' => 2]);
        $this->assertNotSame($originalGb, $newGb1);
        $this->assertSame(['id' => 1], $originalGb->getAttributes());
        $this->assertSame(['id' => 2], $newGb1->getAttributes());

        $newGb2 = $originalGb->withFeatures(['new-feature' => ['defaultValue' => false]]);
        $this->assertNotSame($originalGb, $newGb2);
        $this->assertNotSame($newGb1, $newGb2);

        $newGb3 = $originalGb->withUrl('/new-url');
        $this->assertNotSame($originalGb, $newGb3);
        $this->assertNotSame($newGb1, $newGb3);
        $this->assertNotSame($newGb2, $newGb3);
        $this->assertSame('/original', $originalGb->getUrl());
        $this->assertSame('/new-url', $newGb3->getUrl());
    }
}
