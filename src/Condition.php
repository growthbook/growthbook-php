<?php

namespace Growthbook;

/**
 * Evaluates targeting conditions. Semantics follow the reference JS SDK (mongrule.ts),
 * including its JavaScript type coercion, so the js*() helpers below reproduce how
 * JavaScript converts values to strings, numbers and booleans.
 */
class Condition
{
    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $savedGroups Either bare value lists ($inGroup) or typed
     *                                         entries ($savedGroup), keyed by group id
     * @return bool
     */
    public static function evalCondition(array $attributes, array $condition, array $savedGroups): bool
    {
        return static::evalConditionVisiting($attributes, $condition, $savedGroups, []);
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $savedGroups
     * @param array<string,true> $visited Saved group ids already being resolved, to stop cycles
     * @return bool
     */
    private static function evalConditionVisiting(array $attributes, array $condition, array $savedGroups, array $visited): bool
    {
        foreach ($condition as $key => $value) {
            switch ($key) {
                case '$or':
                    if (!static::evalOr($attributes, $condition['$or'], $savedGroups, $visited)) {
                        return false;
                    }
                    break;

                case '$nor':
                    if (static::evalOr($attributes, $condition['$nor'], $savedGroups, $visited)) {
                        return false;
                    }
                    break;

                case '$and':
                    if (!static::evalAnd($attributes, $condition['$and'], $savedGroups, $visited)) {
                        return false;
                    }
                    break;

                case '$not':
                    if (static::evalConditionVisiting($attributes, $condition['$not'], $savedGroups, $visited)) {
                        return false;
                    }
                    break;

                case '$savedGroup':
                    if (!static::evalSavedGroup($attributes, $value, $savedGroups, $visited)) {
                        return false;
                    }
                    break;

                default:
                    if (!static::evalConditionValue($value, static::getPath($attributes, $key), $savedGroups, false, $visited)) {
                        return false;
                    }
            }
        }
        return true;
    }

    /**
     * Resolves a `$savedGroup` reference such as {"id": "grp", "attributeKey": "backup_id"}.
     * A payload can be newer than this SDK, so anything unrecognized matches nobody.
     *
     * @param array<string,mixed> $attributes
     * @param mixed $reference
     * @param array<string,mixed> $savedGroups
     * @param array<string,true> $visited
     * @return bool
     */
    private static function evalSavedGroup(array $attributes, $reference, array $savedGroups, array $visited): bool
    {
        if (!is_array($reference) || static::isList($reference)) {
            return false;
        }

        $id = $reference['id'] ?? null;
        if (!is_string($id) || isset($visited[$id])) {
            return false;
        }
        // Falling back to the entry's attribute would check a different population
        $attributeKey = $reference['attributeKey'] ?? null;
        if (array_key_exists('attributeKey', $reference) && !is_string($attributeKey)) {
            return false;
        }

        // Absent, or a v1 bare array
        $entry = $savedGroups[$id] ?? null;
        if (!is_array($entry) || static::isList($entry)) {
            return false;
        }

        $type = $entry['type'] ?? null;
        if ($type === 'list') {
            // The override wins over the entry's own attribute
            $key = $attributeKey ?? $entry['attributeKey'] ?? null;
            $values = $entry['values'] ?? null;
            if (!is_string($key) || !static::isList($values)) {
                return false;
            }
            return static::isIn(static::getPath($attributes, $key), $values);
        }

        if ($type === 'condition') {
            // A condition group has no single attribute, so any override is ignored
            $condition = $entry['condition'] ?? null;
            if (!is_array($condition)) {
                return false;
            }
            $visited[$id] = true;
            return static::evalConditionVisiting($attributes, $condition, $savedGroups, $visited);
        }

        // A group type added after this SDK was built
        return false;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed>[] $conditions
     * @param array<string,mixed> $savedGroups
     * @param array<string,true> $visited
     * @return bool
     */
    private static function evalOr(array $attributes, array $conditions, array $savedGroups, array $visited): bool
    {
        if (!count($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (static::evalConditionVisiting($attributes, $condition, $savedGroups, $visited)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed>[] $conditions
     * @param array<string,mixed> $savedGroups
     * @param array<string,true> $visited
     * @return bool
     */
    private static function evalAnd(array $attributes, array $conditions, array $savedGroups, array $visited): bool
    {
        foreach ($conditions as $condition) {
            if (!static::evalConditionVisiting($attributes, $condition, $savedGroups, $visited)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Only a non-empty object whose keys all start with "$" is an operator object
     *
     * @param array<mixed> $obj
     * @return bool
     */
    private static function isOperatorObject(array $obj): bool
    {
        if (!$obj) {
            return false;
        }
        foreach ($obj as $key => $value) {
            if (!is_string($key) || $key === '' || $key[0] !== '$') {
                return false;
            }
        }
        return true;
    }

    /**
     * A decoded JSON array (as opposed to a decoded JSON object)
     *
     * @param mixed $value
     * @return bool
     */
    private static function isList($value): bool
    {
        return is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    }

    /**
     * @param mixed $attributeValue
     * @return string
     */
    private static function getType($attributeValue): string
    {
        if (is_string($attributeValue)) {
            return 'string';
        }
        if (is_array($attributeValue)) {
            return static::isList($attributeValue) ? "array" : "object";
        }
        if (is_object($attributeValue)) {
            return "object";
        }
        if (is_bool($attributeValue)) {
            return "boolean";
        }
        if (is_null($attributeValue)) {
            return "null";
        }
        if (is_numeric($attributeValue)) {
            return "number";
        }
        return "unknown";
    }

    /**
     * @param array<string,mixed> $attributes
     * @param string $path
     * @return mixed
     */
    private static function getPath(array $attributes, string $path)
    {
        $current = $attributes;
        $parts = explode(".", $path);
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    }

    /**
     * @param mixed $conditionValue
     * @param mixed $attributeValue
     * @param array<string,mixed> $savedGroups
     * @param bool $insensitive
     * @param array<string,true> $visited
     * @return bool
     */
    private static function evalConditionValue($conditionValue, $attributeValue, array $savedGroups, bool $insensitive = false, array $visited = []): bool
    {
        // Primitive conditions convert the attribute to the condition's type first, like JS
        // `value + "" === condition`, `value * 1 === condition` and `!!value === condition`
        if (is_string($conditionValue)) {
            if ($insensitive) {
                return static::equalsIgnoreCase(static::jsString($attributeValue), $conditionValue);
            }
            return static::jsString($attributeValue) === $conditionValue;
        }
        if (is_int($conditionValue) || is_float($conditionValue)) {
            return static::jsNumber($attributeValue) === (float) $conditionValue;
        }
        if (is_bool($conditionValue)) {
            return $attributeValue !== null && static::jsTruthy($attributeValue) === $conditionValue;
        }
        if ($conditionValue === null) {
            return $attributeValue === null;
        }

        if (is_array($conditionValue) && static::isOperatorObject($conditionValue)) {
            foreach ($conditionValue as $key => $value) {
                if (!static::evalOperatorCondition($key, $attributeValue, $value, $savedGroups, $visited)) {
                    return false;
                }
            }
            return true;
        }

        // Arrays and plain objects are compared by their JSON serialization
        return static::toJson($conditionValue) === static::toJson($attributeValue);
    }

    /**
     * @param mixed $condition
     * @param mixed $attributeValue
     * @param array<string,mixed> $savedGroups
     * @param array<string,true> $visited
     * @return bool
     */
    private static function elemMatch($condition, $attributeValue, array $savedGroups, array $visited): bool
    {
        if (!static::isList($attributeValue) || !is_array($condition)) {
            return false;
        }

        $isOperatorObject = static::isOperatorObject($condition);
        foreach ($attributeValue as $item) {
            // Only null elements are skipped; falsy values like 0, "" and false are still tested
            if ($item === null) {
                continue;
            }
            if ($isOperatorObject) {
                if (static::evalConditionValue($condition, $item, $savedGroups, false, $visited)) {
                    return true;
                }
                // A scalar element has no properties, so every path into it resolves to null
            } elseif (static::evalConditionVisiting(is_array($item) ? $item : [], $condition, $savedGroups, $visited)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compares two values like the JS relational operators: two strings compare as text,
     * anything else as numbers. Returns null when a number is NaN, which fails every
     * comparison.
     *
     * @param mixed $val1
     * @param mixed $val2
     * @return int|null
     */
    private static function compare($val1, $val2): ?int
    {
        $val1 = static::jsToPrimitive($val1);
        $val2 = static::jsToPrimitive($val2);

        if (is_string($val1) && is_string($val2)) {
            return strcmp($val1, $val2) <=> 0;
        }

        $num1 = static::jsNumber($val1);
        $num2 = static::jsNumber($val2);
        if (is_nan($num1) || is_nan($num2)) {
            return null;
        }
        return $num1 <=> $num2;
    }

    /**
     * Strict equality like JS `===`. Arrays and objects are compared by reference in JS,
     * and two separately decoded values are never the same reference.
     *
     * @param mixed $val1
     * @param mixed $val2
     * @return bool
     */
    private static function isStrictEqual($val1, $val2): bool
    {
        if (is_array($val1) || is_array($val2) || is_object($val1) || is_object($val2)) {
            return false;
        }
        if ((is_int($val1) || is_float($val1)) && (is_int($val2) || is_float($val2))) {
            return (float) $val1 === (float) $val2;
        }
        return $val1 === $val2;
    }

    /**
     * Membership test like JS `Array.prototype.includes`: strict, except that int and
     * float are the same number type and NaN matches NaN.
     *
     * @param mixed $val1
     * @param mixed $val2
     * @return bool
     */
    private static function isSameValueZero($val1, $val2): bool
    {
        if (is_float($val1) && is_float($val2) && is_nan($val1) && is_nan($val2)) {
            return true;
        }
        return static::isStrictEqual($val1, $val2);
    }

    /**
     * @param string $operator
     * @param mixed $attributeValue
     * @param mixed $conditionValue
     * @param array<string,mixed> $savedGroups
     * @param array<string,true> $visited
     * @return bool
     */
    private static function evalOperatorCondition(string $operator, $attributeValue, $conditionValue, array $savedGroups, array $visited): bool
    {
        switch ($operator) {
            case '$eq':
                return static::isStrictEqual($attributeValue, $conditionValue);
            case '$ne':
                return !static::isStrictEqual($attributeValue, $conditionValue);
            case '$lt':
                $result = static::compare($attributeValue, $conditionValue);
                return $result !== null && $result < 0;
            case '$lte':
                $result = static::compare($attributeValue, $conditionValue);
                return $result !== null && $result <= 0;
            case '$gt':
                $result = static::compare($attributeValue, $conditionValue);
                return $result !== null && $result > 0;
            case '$gte':
                $result = static::compare($attributeValue, $conditionValue);
                return $result !== null && $result >= 0;
            case '$veq':
                return static::parseVersionString($attributeValue) === static::parseVersionString($conditionValue);
            case '$vne':
                return static::parseVersionString($attributeValue) !== static::parseVersionString($conditionValue);
            case '$vgt':
                return static::parseVersionString($attributeValue) > static::parseVersionString($conditionValue);
            case '$vgte':
                return static::parseVersionString($attributeValue) >= static::parseVersionString($conditionValue);
            case '$vlt':
                return static::parseVersionString($attributeValue) < static::parseVersionString($conditionValue);
            case '$vlte':
                return static::parseVersionString($attributeValue) <= static::parseVersionString($conditionValue);
            case '$regex':
                return static::regexMatch($conditionValue, $attributeValue) === 1;
            case '$regexi':
                return static::regexMatch($conditionValue, $attributeValue, true) === 1;
            case '$in':
                if (!static::isList($conditionValue)) {
                    return false;
                }
                return static::isIn($attributeValue, $conditionValue, false);
            case '$ini':
                if (!static::isList($conditionValue)) {
                    return false;
                }
                return static::isIn($attributeValue, $conditionValue, true);
            case '$nin':
                if (!static::isList($conditionValue)) {
                    return false;
                }
                return !static::isIn($attributeValue, $conditionValue, false);
            case '$nini':
                if (!static::isList($conditionValue)) {
                    return false;
                }
                return !static::isIn($attributeValue, $conditionValue, true);
            case '$inGroup':
                $values = static::getSavedGroupValues($conditionValue, $savedGroups);
                return $values !== null && static::isIn($attributeValue, $values);
            case '$notInGroup':
                // No values means matching nobody - treating them as an empty list would pass everyone
                $values = static::getSavedGroupValues($conditionValue, $savedGroups);
                return $values !== null && !static::isIn($attributeValue, $values);
            case '$elemMatch':
                return static::elemMatch($conditionValue, $attributeValue, $savedGroups, $visited);
            case '$size':
                if (!static::isList($attributeValue)) {
                    return false;
                }
                return static::evalConditionValue($conditionValue, count($attributeValue), $savedGroups, false, $visited);
            case '$all':
                if (!static::isList($attributeValue) || !static::isList($conditionValue)) {
                    return false;
                }
                return static::isInAll($attributeValue, $conditionValue, $savedGroups, false, $visited);
            case '$alli':
                if (!static::isList($attributeValue) || !static::isList($conditionValue)) {
                    return false;
                }
                return static::isInAll($attributeValue, $conditionValue, $savedGroups, true, $visited);
            case '$exists':
                // JS truthiness: [], {} and "0" ask for a present attribute too
                if (!static::jsTruthy($conditionValue)) {
                    return $attributeValue === null;
                }
                return $attributeValue !== null;
            case '$type':
                return static::getType($attributeValue) === $conditionValue;
            case '$not':
                return !static::evalConditionValue($conditionValue, $attributeValue, $savedGroups, false, $visited);
            default:
                return false;
        }
    }

    /**
     * Members of a saved group for $inGroup / $notInGroup: either a bare value list or a
     * typed "list" entry. An id missing from the payload has always behaved like an empty
     * list; any other entry (a condition group, an unknown type, a malformed value) returns
     * null so that both operators fail closed.
     *
     * @param mixed $groupId
     * @param array<string,mixed> $savedGroups
     * @return array<mixed>|null
     */
    private static function getSavedGroupValues($groupId, array $savedGroups): ?array
    {
        // A JS property lookup converts the key to text, so a null id looks up the group "null"
        $groupId = static::jsString($groupId);
        if (!array_key_exists($groupId, $savedGroups)) {
            return [];
        }
        $entry = $savedGroups[$groupId];
        if (static::isList($entry)) {
            return $entry;
        }
        // These operators take their attribute from the condition they sit on, not the entry
        if (is_array($entry) && ($entry['type'] ?? null) === 'list') {
            $values = $entry['values'] ?? null;
            return static::isList($values) ? $values : null;
        }
        return null;
    }

    /**
     * @param mixed $version
     * @return string
     */
    public static function parseVersionString($version): string
    {
        if (is_int($version) || is_float($version)) {
            $version = (string) $version;
        }
        if (!$version || !is_string($version)) {
            $version = "0";
        }
        // Remove build info and leading `v` if any
        // Split version into parts (both core version numbers and pre-release tags)
        // "v1.2.3-rc.1+build123" -> ["1","2","3","rc","1"]
        $parts = preg_split('/[-.]/', preg_replace('/(^v|\+.*$)/', '', $version) ?? '');

        // Unable to parse
        if (!is_array($parts)) {
            return $version;
        }

        // If it's SemVer without a pre-release, add `~` to the end
        // ["1","0","0"] -> ["1","0","0","~"]
        // "~" is the largest ASCII character, so this will make "1.0.0" greater than "1.0.0-beta" for example
        if (count($parts) === 3) {
            $parts[] = '~';
        }

        # Left pad each numeric part with spaces so string comparisons will work ("9">"10", but " 9"<"10")
        $parts = array_map(function ($part) {
            return preg_match('/^[0-9]+$/', $part) ? str_pad($part, 5, " ", STR_PAD_LEFT) : $part;
        }, $parts);

        return implode('-', $parts);
    }

    /**
     * An array attribute matches when any of its elements is in the list. An object
     * attribute never matches.
     *
     * @param mixed $attributeValue
     * @param array<mixed> $conditionValue
     * @param bool $insensitive
     * @return bool
     */
    private static function isIn($attributeValue, array $conditionValue, bool $insensitive = false): bool
    {
        if (!static::isList($attributeValue)) {
            $attributeValue = [$attributeValue];
        }

        foreach ($attributeValue as $actual) {
            foreach ($conditionValue as $expected) {
                if ($insensitive && is_string($actual) && is_string($expected)) {
                    if (static::equalsIgnoreCase($actual, $expected)) {
                        return true;
                    }
                } elseif (static::isSameValueZero($actual, $expected)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $attributeValue
     * @param array<mixed> $conditionValue
     * @param array<string,mixed> $savedGroups
     * @param bool $insensitive
     * @param array<string,true> $visited
     * @return bool
     */
    private static function isInAll(array $attributeValue, array $conditionValue, array $savedGroups, bool $insensitive, array $visited): bool
    {
        foreach ($conditionValue as $a) {
            $pass = false;
            foreach ($attributeValue as $b) {
                if (static::evalConditionValue($a, $b, $savedGroups, $insensitive, $visited)) {
                    $pass = true;
                    break;
                }
            }
            if (!$pass) {
                return false;
            }
        }
        return true;
    }

    /**
     * Runs preg_match for a condition regex pattern against an attribute value.
     * Returns 1 (match), 0 (no match) or false (invalid/incomparable pattern),
     * mirroring preg_match's own return values so callers can distinguish a
     * real "no match" from an unmatchable pattern.
     *
     * Like JS RegExp.test(), the attribute is converted to text first, so an absent
     * attribute is matched as "null". A non-string pattern is never a match.
     *
     * @param mixed $pattern
     * @param mixed $value
     * @param bool $caseInsensitive
     * @return int|false
     */
    private static function regexMatch($pattern, $value, bool $caseInsensitive = false)
    {
        if (!is_string($pattern)) {
            return false;
        }
        // Escape any "/" that is not already escaped, as it is the PCRE delimiter
        $escaped = preg_replace('~(?<!\\\\)((?:\\\\\\\\)*)/~', '$1\\/', $pattern) ?? $pattern;
        // D: "$" matches only at the very end, not before a trailing newline, as in JS.
        // u: patterns and attributes are UTF-8, so "." and case-insensitivity work on characters
        // rather than bytes; an attribute that is not valid UTF-8 is not a match.
        $modifiers = $caseInsensitive ? 'iDu' : 'Du';
        return @preg_match('/' . $escaped . '/' . $modifiers, static::jsString($value));
    }

    /**
     * Case-insensitive comparison like JS `a.toLowerCase() === b.toLowerCase()`, which
     * folds any Unicode letter, not only ASCII
     *
     * @param string $val1
     * @param string $val2
     * @return bool
     */
    private static function equalsIgnoreCase(string $val1, string $val2): bool
    {
        return mb_strtolower($val1, 'UTF-8') === mb_strtolower($val2, 'UTF-8');
    }

    /**
     * Converts a value to text like JS `String(value)`
     *
     * @param mixed $value
     * @return string
     */
    private static function jsString($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'Infinity' : '-Infinity';
            }
            if (floor($value) === $value && abs($value) < 1e21) {
                return sprintf('%.0f', $value);
            }
            return (string) json_encode($value);
        }
        if (static::isList($value)) {
            // An array joins its elements with commas, rendering null elements as empty
            return implode(',', array_map(function ($item) {
                return $item === null ? '' : static::jsString($item);
            }, $value));
        }
        return '[object Object]';
    }

    /**
     * Converts a value to a number like JS `Number(value)`
     *
     * @param mixed $value
     * @return float
     */
    private static function jsNumber($value): float
    {
        if ($value === null) {
            return 0.0;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_array($value) || is_object($value)) {
            $primitive = static::jsToPrimitive($value);
            return is_string($primitive) ? static::jsNumber($primitive) : NAN;
        }
        if (!is_string($value)) {
            return NAN;
        }

        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $value)) {
            return (float) $value;
        }
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $value, $m)) {
            return (float) hexdec($m[1]);
        }
        if (preg_match('/^0[oO]([0-7]+)$/', $value, $m)) {
            return (float) octdec($m[1]);
        }
        if (preg_match('/^0[bB]([01]+)$/', $value, $m)) {
            return (float) bindec($m[1]);
        }
        if (preg_match('/^([+-]?)Infinity$/', $value, $m)) {
            return $m[1] === '-' ? -INF : INF;
        }
        return NAN;
    }

    /**
     * Arrays and objects become text, like JS ToPrimitive does before comparing them
     *
     * @param mixed $value
     * @return mixed
     */
    private static function jsToPrimitive($value)
    {
        if (is_array($value) || is_object($value)) {
            return static::jsString($value);
        }
        return $value;
    }

    /**
     * Truthiness like JS `!!value`: arrays and objects are always truthy, even when empty
     *
     * @param mixed $value
     * @return bool
     */
    private static function jsTruthy($value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return false;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0 && !is_nan((float) $value);
        }
        return true;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function toJson($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }
}
