<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\Condition;
use Growthbook\Growthbook;
use Growthbook\InlineExperiment;
use PHPUnit\Framework\TestCase;

final class GrowthbookTest extends TestCase
{
    /**
     * @var array<string,array<int,mixed[]>>
     */
    private $cases;

    /**
     * @return array<int|string,mixed[]>
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
    public function testHash(string $seed, string $value, int $version, float $expected = null): void
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
    public function testDecrypt(string $encryptedString, string $key, string $expected = null): void
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
     */
    public function removeNulls($obj, array $ref): array
    {
        $encoded = json_encode($obj);
        if (!$encoded) {
            throw new \Exception("Failed to encode object");
        }
        $arr = json_decode($encoded, true);
        foreach ($arr as $k => $v) {
            if ($v === null || ($k === "active" && $v && !isset($ref['active'])) || ($k === "coverage" && $v === 1 && !isset($ref['coverage'])) || ($k==="hashAttribute" && $v==="id" && !isset($ref['hashAttribute']))) {
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
        $attributes = ['id'=>1];
        $callback = function ($exp, $res) {
            // do nothing
        };
        $features = [
            'feature-1'=>['defaultValue'=>1, 'rules'=>[]]
        ];
        $url = "/home";
        $forcedVariations = ['exp1'=>0];

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

    public function testInlineExperiment(): void
    {
        $condition = ['country'=>'US'];
        $weights = [.4,.6];
        $coverage = 0.5;
        $hashAttribute = 'anonId';

        $exp = InlineExperiment::create("my-test", [0,1])
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
            [$this->equalTo("debug"),$this->stringContains("Evaluating feature")],
            [$this->equalTo("debug"),$this->stringContains("Attempting to run experiment")],
            [$this->equalTo("debug"),$this->stringContains("Assigned user a variation")],
            [$this->equalTo("debug"),$this->stringContains("Use feature value from experiment")],
        );

        $gb = Growthbook::create()
            ->withTrackingCallback($callback)
            ->withLogger($logger)
            ->withAttributes(['id' => '1'])
            ->withFeatures([
                'feature'=>[
                    'defaultValue'=>false,
                    'rules'=>[
                        [
                            'variations'=>[false,true],
                            'meta'=>[
                                ['key'=>'control'],
                                ['key'=>'variation']
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
}
