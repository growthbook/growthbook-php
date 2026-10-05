<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\Condition;
use Growthbook\Growthbook;
use PHPUnit\Framework\TestCase;

/**
 * Operator edge cases the shared cases.json does not cover. Every expectation is what the
 * reference JS SDK (mongrule.ts) returns, including its JavaScript type coercion.
 */
final class ConditionOperatorTest extends TestCase
{
    /**
     * @dataProvider operatorProvider
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $savedGroups
     */
    public function testOperator(array $condition, array $attributes, bool $expected, array $savedGroups = []): void
    {
        $this->assertSame($expected, Condition::evalCondition($attributes, $condition, $savedGroups));
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:bool,3?:array<string,mixed>}>
     */
    public function operatorProvider(): array
    {
        $groups = [
            'letters' => ['a', 'b'],
            'broken' => null,
            'typed' => ['type' => 'condition', 'condition' => ['plan' => 'pro']],
        ];

        return [
            // Plain equality converts the attribute to the condition's type
            'number condition matches a numeric string' => [['a' => 25], ['a' => '25'], true],
            'string condition matches a number' => [['a' => '25'], ['a' => 25], true],
            'true condition matches a truthy number' => [['a' => true], ['a' => 1], true],
            'true condition matches a non-empty string' => [['a' => true], ['a' => 'x'], true],
            'false condition matches zero' => [['a' => false], ['a' => 0], true],
            'false condition does not match an absent attribute' => [['a' => false], [], false],
            'integer condition matches an integral float' => [['a' => 25], ['a' => 25.0], true],
            'string "null" matches an absent attribute' => [['a' => 'null'], [], true],
            'string condition matches a one-element array as text' => [['a' => 'x'], ['a' => ['x']], true],
            'string condition does not match a different array' => [['a' => 'x'], ['a' => ['y']], false],
            'number condition does not match an object' => [['a' => 5], ['a' => ['k' => 1]], false],

            // $eq / $ne are strict, and arrays are never the same reference
            '$eq does not convert a number to a string' => [['a' => ['$eq' => '25']], ['a' => 25], false],
            '$eq does not treat an absent attribute as zero' => [['a' => ['$eq' => 0]], [], false],
            '$eq does not convert a string to a boolean' => [['a' => ['$eq' => true]], ['a' => 'true'], false],
            '$eq is false for equal arrays' => [['a' => ['$eq' => ['x']]], ['a' => ['x']], false],
            '$ne is true for equal arrays' => [['a' => ['$ne' => ['x']]], ['a' => ['x']], true],
            '$eq treats int and float as one number type' => [['a' => ['$eq' => 1]], ['a' => 1.0], true],

            // Range operators compare two strings as text and anything else as numbers
            'two numeric strings compare as text' => [['a' => ['$lt' => '10']], ['a' => '3'], false],
            'a boolean compares as zero or one' => [['a' => ['$lt' => 1]], ['a' => false], true],
            'a non-numeric string is NaN for $lt' => [['a' => ['$lt' => 1]], ['a' => 'abc'], false],
            'a non-numeric string is NaN for $gte' => [['a' => ['$gte' => 1]], ['a' => 'abc'], false],
            'a hex string is read as a number' => [['a' => ['$gt' => 15]], ['a' => '0x10'], true],
            'an absent attribute compares as zero' => [['a' => ['$lt' => 1]], [], true],
            'a one-element array compares as its element' => [['a' => ['$lt' => 10]], ['a' => [5]], true],
            'a numeric string compares with a number' => [['a' => ['$gt' => 4]], ['a' => '5'], true],

            // $exists uses JS truthiness
            '$exists with an empty array asks for a present attribute' => [['a' => ['$exists' => []]], ['a' => 1], true],
            '$exists with "0" asks for a present attribute' => [['a' => ['$exists' => '0']], ['a' => 1], true],
            '$exists false matches an absent attribute' => [['a' => ['$exists' => false]], [], true],

            // The regex family matches the attribute's JS text
            'regex matches a boolean as text' => [['a' => ['$regex' => '^true$']], ['a' => true], true],
            'regex matches an array as joined text' => [['a' => ['$regex' => '^a,b$']], ['a' => ['a', 'b']], true],
            'regex matches an object as object text' => [['a' => ['$regex' => 'object']], ['a' => ['k' => 1]], true],
            'regex matches an integral float without a fraction' => [['a' => ['$regex' => '^25$']], ['a' => 25.0], true],
            'a non-string pattern is not a match' => [['a' => ['$regex' => 12]], ['a' => '12'], false],
            'an unescaped slash in the pattern works' => [['a' => ['$regex' => 'a/b']], ['a' => 'a/b'], true],
            'an escaped slash in the pattern works' => [['a' => ['$regex' => 'a\/b']], ['a' => 'a/b'], true],
            'regex matches an absent attribute as "null"' => [['a' => ['$regex' => 'ull']], [], true],
            'an absent attribute is not empty text' => [['a' => ['$regex' => '^$']], [], false],
            '$regexi matches an absent attribute as "null"' => [['a' => ['$regexi' => 'NULL']], [], true],
            'an invalid pattern is not a match' => [['a' => ['$regex' => '(']], ['a' => '('], false],
            '$ does not match before a trailing newline' => [['a' => ['$regex' => '^\d+$']], ['a' => "123\n"], false],
            '$regexi $ does not match before a trailing newline' => [['a' => ['$regexi' => '^abc$']], ['a' => "ABC\n"], false],
            '$ still matches at the end' => [['a' => ['$regex' => '^\d+$']], ['a' => '123'], true],

            // $elemMatch
            '$elemMatch skips null elements' => [['a' => ['$elemMatch' => ['$exists' => false]]], ['a' => [null]], false],
            '$elemMatch with a non-operator body tolerates scalar elements' => [['a' => ['$elemMatch' => ['k' => 'v']]], ['a' => ['s', 1]], false],
            '$elemMatch still tests a zero element' => [['a' => ['$elemMatch' => ['$eq' => 0]]], ['a' => [0]], true],

            // Membership
            '$in intersects an array attribute' => [['a' => ['$in' => ['a', 'b']]], ['a' => ['b', 'z']], true],
            '$in is strict about types' => [['a' => ['$in' => [1]]], ['a' => '1'], false],
            '$in never matches an object attribute' => [['a' => ['$in' => ['RU']]], ['a' => ['k' => 'RU']], false],
            '$in with a non-array value is false' => [['a' => ['$in' => 'x']], ['a' => 'x'], false],
            '$nin with a non-array value is false' => [['a' => ['$nin' => 'x']], ['a' => 'y'], false],
            '$nin passes for an absent attribute' => [['a' => ['$nin' => ['x']]], [], true],
            '$inGroup intersects an array attribute' => [['a' => ['$inGroup' => 'letters']], ['a' => ['b', 'z']], true, $groups],
            '$notInGroup fails for an array attribute with a member' => [['a' => ['$notInGroup' => 'letters']], ['a' => ['b', 'z']], false, $groups],
            '$inGroup fails closed for a null entry' => [['a' => ['$inGroup' => 'broken']], ['a' => 'a'], false, $groups],
            '$notInGroup fails closed for a null entry' => [['a' => ['$notInGroup' => 'broken']], ['a' => 'a'], false, $groups],
            '$notInGroup fails closed for an entry that is not a list' => [['a' => ['$notInGroup' => 'typed']], ['a' => 'a'], false, $groups],
            '$notInGroup still passes for an id missing from the payload' => [['a' => ['$notInGroup' => 'missing']], ['a' => 'a'], true, $groups],
            '$notInGroup passes for a null id, which looks up the missing group "null"' => [['a' => ['$notInGroup' => null]], ['a' => 'a'], true, $groups],
            '$inGroup fails for a null id' => [['a' => ['$inGroup' => null]], ['a' => 'a'], false, $groups],
            'a non-string id is looked up as text' => [['a' => ['$inGroup' => true]], ['a' => 'a'], true, ['true' => ['a']]],

            // Other
            '$type of an absent attribute is null' => [['a' => ['$type' => 'null']], [], true],
            'an unknown top-level key is an attribute path' => [['$foo' => 1], [], false],
            'an unknown operator is false' => [['a' => ['$foo' => 1]], ['a' => 1], false],
        ];
    }

    public function testPrerequisiteWithoutConditionDoesNotThrow(): void
    {
        // A parent condition with no `condition` key passes, like an empty condition in JS
        $gb = new Growthbook([
            'features' => [
                'parent' => ['defaultValue' => true],
                'child' => [
                    'defaultValue' => false,
                    'rules' => [['parentConditions' => [['id' => 'parent']], 'force' => true]],
                ],
            ],
        ]);

        $this->assertTrue($gb->getFeature('child')->value);
    }
}
