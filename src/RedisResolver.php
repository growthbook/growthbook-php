<?php

namespace Growthbook;

/**
 * A resolver for Growthbook::addRemoteSavedGroups, for remote saved groups loaded
 * into Redis by GrowthBook's loader: one `SMEMBERS gb:member:<attribute>:<value>`
 * per attribute value.
 *
 * Takes any object with `sMembers($key)`, such as a phpredis `\Redis` or a Predis
 * client (PHP method names are case-insensitive); the SDK doesn't depend on either.
 *
 * A derived group checks its own attribute's value in its source group's sets:
 * `grp_vip:parent_id` looks up `gb:member:<grp_vip's attribute>:<parent_id>` and
 * matches when `grp_vip` comes back.
 */
class RedisResolver
{
    /** @var object */
    private $redis;

    /**
     * @param object $redis A Redis client with sMembers
     */
    public function __construct(object $redis)
    {
        $this->redis = $redis;
    }

    /**
     * @param array<string,mixed> $attributes The attributes the groups use, keyed by attributeKey
     * @param array<int,array{id:string,attributeKey:string,valuesFrom?:string}> $groups The payload's remote groups
     * @return string[] The ids of the groups the user is in
     */
    public function __invoke(array $attributes, array $groups): array
    {
        // For each group and value: the Redis key, and the id that means a match
        $checks = [];
        foreach ($groups as $group) {
            $source = $group;
            if (isset($group['valuesFrom'])) {
                $source = null;
                foreach ($groups as $g) {
                    if ($g['id'] === $group['valuesFrom']) {
                        $source = $g;
                        break;
                    }
                }
            }
            if ($source === null) {
                continue;
            }
            foreach (self::lookupValues($attributes[$group['attributeKey']] ?? null) as $value) {
                $checks[] = [$group['id'], 'gb:member:' . $source['attributeKey'] . ':' . $value, $source['id']];
            }
        }

        $members = [];
        foreach ($checks as [, $key]) {
            if (array_key_exists($key, $members)) {
                continue;
            }
            // @phpstan-ignore-next-line Any client with sMembers
            $set = $this->redis->sMembers($key);
            if (!is_array($set)) {
                throw new \UnexpectedValueException('SMEMBERS did not return a list');
            }
            $members[$key] = array_map('strval', $set);
        }

        // Checking for the expected id, not any id in the set, also keeps keys that
        // collide when attributes or values contain ":" (`a:b` + `c` and `a` + `b:c`)
        // from mixing up groups.
        $ids = [];
        foreach ($checks as [$id, $key, $matchId]) {
            if (in_array($matchId, $members[$key], true) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Each item of a list is looked up. Numbers use JS `String(value)`, the same
     * form the loader stores them in.
     *
     * @param mixed $value
     * @return string[]
     */
    private static function lookupValues($value): array
    {
        $values = [];
        foreach (is_array($value) && self::isList($value) ? $value : [$value] as $v) {
            if (is_string($v)) {
                $values[] = $v;
            } elseif ((is_int($v) || is_float($v)) && is_finite((float) $v)) {
                $values[] = self::jsNumberToString($v);
            }
        }
        return $values;
    }

    /**
     * A JS array, rather than an object
     *
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Formats a number like JS String(number): 2.0 -> "2", 0.00001 -> "0.00001",
     * 1e21 -> "1e+21"
     *
     * @param int|float $number
     */
    private static function jsNumberToString($number): string
    {
        if (is_int($number)) {
            return (string) $number;
        }
        if ($number == 0) {
            return '0';
        }
        if ($number < 0) {
            return '-' . self::jsNumberToString(-$number);
        }
        // The shortest digits that read back as the same number, as JS uses
        $exponential = sprintf('%.16e', $number);
        for ($precision = 0; $precision < 17; $precision++) {
            $exponential = sprintf("%.{$precision}e", $number);
            if ((float) $exponential === $number) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $exponential);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        $k = strlen($digits);
        // $number = 0.<digits> * 10 ** $point
        $point = (int) $exponent + 1;
        if ($k <= $point && $point <= 21) {
            return $digits . str_repeat('0', $point - $k);
        }
        if ($point > 0 && $point <= 21) {
            return substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        if ($point > -6 && $point <= 0) {
            return '0.' . str_repeat('0', -$point) . $digits;
        }
        $e = $point - 1;
        return $digits[0] . ($k > 1 ? '.' . substr($digits, 1) : '') . 'e' . ($e < 0 ? '-' : '+') . abs($e);
    }
}
