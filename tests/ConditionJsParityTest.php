<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\Condition;
use PHPUnit\Framework\TestCase;

/**
 * Condition cases whose expected values were generated with the JavaScript SDK's evalCondition.
 */
final class ConditionJsParityTest extends TestCase
{
    /**
     * @dataProvider jsParityProvider
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $attributes
     */
    public function testMatchesJavaScript(array $condition, array $attributes, bool $expected): void
    {
        $this->assertSame($expected, Condition::evalCondition($attributes, $condition, []));
    }

    /**
     * @return array<int|string,mixed[]>
     */
    public function jsParityProvider(): array
    {
        $json = file_get_contents(__DIR__ . '/condition-js-parity-cases.json');
        if (!$json) {
            throw new \Exception("Could not load condition-js-parity-cases.json");
        }
        $cases = [];
        foreach (json_decode($json, true) as $row) {
            $cases[$row[0]] = array_slice($row, 1);
        }
        return $cases;
    }

    /**
     * JavaScript has a single number type, so ints and floats with the same value are equal.
     *
     * @dataProvider intFloatProvider
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $attributes
     */
    public function testIntAndFloatAreTheSameNumber(array $condition, array $attributes, bool $expected): void
    {
        $this->assertSame($expected, Condition::evalCondition($attributes, $condition, []));
    }

    /**
     * @return array<string,mixed[]>
     */
    public function intFloatProvider(): array
    {
        return [
            'int condition vs float attribute' => [['n' => 1], ['n' => 1.0], true],
            'float condition vs int attribute' => [['n' => 30.0], ['n' => 30], true],
            'float condition vs numeric string' => [['n' => 30.0], ['n' => '30'], true],
            'string condition vs whole float attribute' => [['n' => '10'], ['n' => 10.0], true],
            'string condition 10.0 vs whole float attribute' => [['n' => '10.0'], ['n' => 10.0], false],
            'string condition vs large float attribute' => [['n' => '1e+21'], ['n' => 1.0E+21], true],
            'string condition vs float below 1e21' => [['n' => '100000000000000000000'], ['n' => 1.0E+20], true],
            'string condition vs 0.1 + 0.2' => [['n' => '0.30000000000000004'], ['n' => 0.1 + 0.2], true],
            'string condition vs negative zero' => [['n' => '0'], ['n' => -0.0], true],
        ];
    }
}
