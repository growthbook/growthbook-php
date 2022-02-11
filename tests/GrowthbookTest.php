<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\Condition;
use Growthbook\Growthbook;
use Growthbook\InlineExperiment;
use PHPUnit\Framework\TestCase;

final class GrowthbookTest extends TestCase
{
    private $cases;

    protected function getCases(string $section, bool $extractName = true): array
    {
        if (!$this->cases) {
            $cases = file_get_contents(__DIR__ . '/cases.json');
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
    public function getBucketRangeProvider(): array
    {
        return $this->getCases("getBucketRange");
    }

    /**
     * @dataProvider hashProvider
     */
    public function testHash(string $value, float $expected): void
    {
        $this->assertSame(Growthbook::hash($value), $expected);
    }
    public function hashProvider(): array
    {
        return $this->getCases("hash", false);
    }

    /**
     * @dataProvider evalConditionProvider
     */
    public function testEvalCondition(array $condition, array $attributes, bool $expected): void
    {
        $this->assertSame(Condition::evalCondition($attributes, $condition), $expected);
    }
    public function evalConditionProvider(): array
    {
        return $this->getCases("evalCondition");
    }


    /**
     * @dataProvider getQueryStringOverrideProvider
     */
    public function testGetQueryStringOverride(string $key, string $url, int $numVariations, $expected): void
    {
        $this->assertSame(Growthbook::getQueryStringOverride($key, $url, $numVariations), $expected);
    }
    public function getQueryStringOverrideProvider(): array
    {
        return $this->getCases("getQueryStringOverride");
    }


    /**
     * @dataProvider chooseVariationProvider
     */
    public function testChooseVariation(float $n, array $ranges, int $expected): void
    {
        $this->assertSame(Growthbook::chooseVariation($n, $ranges), $expected);
    }
    public function chooseVariationProvider(): array
    {
        return $this->getCases("chooseVariation");
    }


    /**
     * @dataProvider inNamespaceProvider
     */
    public function testInNamespace(string $id, array $namespace, $expected): void
    {
        $this->assertSame(Growthbook::inNamespace($id, $namespace), $expected);
    }
    public function inNamespaceProvider(): array
    {
        return $this->getCases("inNamespace");
    }


    /**
     * @dataProvider getEqualWeightsProvider
     */
    public function testGetEqualWeights(int $numVariations, $expected): void
    {
        $weights = Growthbook::getEqualWeights($numVariations);

        $this->assertSame(array_map(function ($w) {
            return round($w, 8);
        }, $weights), array_map(function ($w) {
            return round($w, 8);
        }, $expected));
    }
    public function getEqualWeightsProvider(): array
    {
        return $this->getCases("getEqualWeights", false);
    }


    /**
     * @dataProvider featureProvider
     */
    public function testFeature($ctx, string $key, $expected): void
    {
        $gb = new Growthbook($ctx);
        $res = $gb->feature($key);

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
    public function removeNulls($obj, $ref): array
    {
        $arr = json_decode(json_encode($obj), true);
        foreach ($arr as $k => $v) {
            if ($v === null || ($k === "active" && $v && !isset($ref['active'])) || ($k === "coverage" && $v === 1 && !isset($ref['coverage'])) || ($k==="hashAttribute" && $v==="id" && !isset($ref['hashAttribute']))) {
                unset($arr[$k]);
            }
        }

        return $arr;
    }
    public function featureProvider(): array
    {
        return $this->getCases("feature");
    }



    /**
     * @dataProvider getRunProvider
     */
    public function testRun($ctx, $exp, $expectedValue, $inExperiment): void
    {
        $gb = new Growthbook($ctx);
        $experiment = new InlineExperiment($exp["key"], $exp["variations"], $exp);
        $res = $gb->run($experiment);

        $this->assertSame($res->value, $expectedValue);
        $this->assertSame($res->inExperiment, $inExperiment);
    }
    public function getRunProvider(): array
    {
        return $this->getCases("run");
    }
}
