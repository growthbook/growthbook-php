<?php

namespace Growthbook;

class Condition
{
    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $savedGroups
     * @return bool
     */
    public static function evalCondition(array $attributes, array $condition, array $savedGroups): bool
    {
        foreach ($condition as $key => $value) {
            switch ($key) {
                case '$or':
                    if (!static::evalOr($attributes, $condition['$or'], $savedGroups)) {
                        return false;
                    }
                    break;

                case '$nor':
                    if (static::evalOr($attributes, $condition['$nor'], $savedGroups)) {
                        return false;
                    }
                    break;

                case '$and':
                    if (!static::evalAnd($attributes, $condition['$and'], $savedGroups)) {
                        return false;
                    }
                    break;

                case '$not':
                    if (static::evalCondition($attributes, $condition['$not'], $savedGroups)) {
                        return false;
                    }
                    break;

                default:
                    if (!static::evalConditionValue($value, static::getPath($attributes, $key), $savedGroups)) {
                        return false;
                    }
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed>[] $conditions
     * @param array<string,mixed> $savedGroups
     * @return bool
     */
    private static function evalOr(array $attributes, array $conditions, array $savedGroups): bool
    {
        if (!count($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (static::evalCondition($attributes, $condition, $savedGroups)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed>[] $conditions
     * @param array<string,mixed> $savedGroups
     * @return bool
     */
    private static function evalAnd(array $attributes, array $conditions, array $savedGroups): bool
    {
        foreach ($conditions as $condition) {
            if (!static::evalCondition($attributes, $condition, $savedGroups)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $obj
     * @return bool
     */
    private static function isOperatorObject(array $obj): bool
    {
        foreach ($obj as $key => $value) {
            if (!is_string($key) || $key[0] !== '$') {
                return false;
            }
        }
        return true;
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
            if ($attributeValue === [] || (array_keys($attributeValue) === range(0, count($attributeValue) - 1))) {
                return "array";
            }
            return "object";
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
     * @return bool
     */
    private static function evalConditionValue($conditionValue, $attributeValue, array $savedGroups, bool $insensitive = false): bool
    {
        if ($conditionValue === null) {
            return $attributeValue === null;
        }
        // Simple equality comparison with optional case-insensitivity
        if ($insensitive && is_string($conditionValue) && is_string($attributeValue)) {
            return strcasecmp($conditionValue, $attributeValue) === 0;
        }

        $type = static::getType($conditionValue);

        switch ($type) {
            case 'string':
            case 'number':
            case 'boolean':
                return static::isEqual($conditionValue, $attributeValue);
            case 'array':
                return static::getType($attributeValue) === $type && static::toJson($conditionValue) === static::toJson($attributeValue);
            case 'object':
                if (static::isOperatorObject($conditionValue)) {
                    foreach ($conditionValue as $key => $value) {
                        if (!static::evalOperatorCondition($key, $attributeValue, $value, $savedGroups)) {
                            return false;
                        }
                    }
                    return true;
                }
                return static::getType($attributeValue) === $type && static::toJson($conditionValue) === static::toJson($attributeValue);

            case 'null':
                return $attributeValue === null;
            default:
                return strval($conditionValue) === strval($attributeValue);
        }
    }

    /**
     * @param array<string,mixed> $condition
     * @param mixed $attributeValue
     * @param array<string,mixed> $savedGroups
     * @return bool
     */
    private static function elemMatch(array $condition, $attributeValue, array $savedGroups): bool
    {
        if (!is_array($attributeValue)) {
            return false;
        }

        foreach ($attributeValue as $item) {
            if (static::isOperatorObject($condition)) {
                if (static::evalConditionValue($condition, $item, $savedGroups)) {
                    return true;
                }
            } elseif (static::evalCondition($item, $condition, $savedGroups)) {
                return true;
            }
        }
        return false;
    }


    /**
     * Strict equality: values of different types are never equal. Integers and
     * floats are both JSON numbers, so 1 equals 1.0.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    private static function isEqual($a, $b): bool
    {
        if (static::getType($a) !== static::getType($b)) {
            return false;
        }
        if (static::getType($a) === 'number') {
            return $a == $b;
        }
        return $a === $b;
    }

    /**
     * Only two numbers or two strings can be ordered. Strings compare as text.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    private static function isOrderable($a, $b): bool
    {
        $type = static::getType($a);
        return ($type === 'number' || $type === 'string') && $type === static::getType($b);
    }

    /**
     * @param mixed $a
     * @param mixed $b
     * @return int
     */
    private static function compare($a, $b): int
    {
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }
        return $a <=> $b;
    }

    /**
     * @param string $operator
     * @param mixed $attributeValue
     * @param mixed $conditionValue
     * @param mixed $savedGroups
     * @return bool
     */
    private static function evalOperatorCondition(string $operator, $attributeValue, $conditionValue, $savedGroups): bool
    {
        switch ($operator) {
            case '$eq':
                return static::isEqual($attributeValue, $conditionValue);
            case '$ne':
                return !static::isEqual($attributeValue, $conditionValue);
            case '$lt':
                return static::isOrderable($attributeValue, $conditionValue) && static::compare($attributeValue, $conditionValue) < 0;
            case '$lte':
                return static::isOrderable($attributeValue, $conditionValue) && static::compare($attributeValue, $conditionValue) <= 0;
            case '$gt':
                return static::isOrderable($attributeValue, $conditionValue) && static::compare($attributeValue, $conditionValue) > 0;
            case '$gte':
                return static::isOrderable($attributeValue, $conditionValue) && static::compare($attributeValue, $conditionValue) >= 0;
            case '$veq':
            case '$vne':
            case '$vgt':
            case '$vgte':
            case '$vlt':
            case '$vlte':
                return static::evalVersion($operator, $attributeValue, $conditionValue);
            case '$regex':
                return is_string($attributeValue) && static::regexMatch($conditionValue, $attributeValue) === 1;
            case '$regexi':
                return is_string($attributeValue) && static::regexMatch($conditionValue, $attributeValue, true) === 1;
            case '$in':
                if (!is_array($conditionValue)) {
                    return false;
                }
                return static::isIn($attributeValue, $conditionValue, false);
            case '$ini':
                if (!is_array($conditionValue)) {
                    return false;
                }
                return static::isIn($attributeValue, $conditionValue, true);
            case '$nin':
                if (!is_array($conditionValue)) {
                    return false;
                }
                return !static::isIn($attributeValue, $conditionValue, false);
            case '$nini':
                if (!is_array($conditionValue)) {
                    return false;
                }
                return !static::isIn($attributeValue, $conditionValue, true);
            case '$inGroup':
                if (!array_key_exists($conditionValue, $savedGroups)) {
                    return false;
                }
                if (!is_array($attributeValue)) {
                    $attributeValue = [$attributeValue];
                }
                return array_uintersect($attributeValue, $savedGroups[$conditionValue], function ($a, $b) {
                    return $a === $b ? 0 : 1;
                }) !== [];
            case '$notInGroup':
                if (!array_key_exists($conditionValue, $savedGroups)) {
                    return true;
                }
                if (!is_array($attributeValue)) {
                    $attributeValue = [$attributeValue];
                }
                return array_uintersect($attributeValue, $savedGroups[$conditionValue], function ($a, $b) {
                    return $a === $b ? 0 : 1;
                }) === [];
            case '$elemMatch':
                return static::elemMatch($conditionValue, $attributeValue, $savedGroups);
            case '$size':
                if (!is_array($attributeValue)) {
                    return false;
                }
                return static::evalConditionValue($conditionValue, count($attributeValue), $savedGroups);
            case '$all':
                if (!is_array($attributeValue) || !is_array($conditionValue)) {
                    return false;
                }
                return static::isInAll($attributeValue, $conditionValue, $savedGroups, false);
            case '$alli':
                if (!is_array($attributeValue) || !is_array($conditionValue)) {
                    return false;
                }
                return static::isInAll($attributeValue, $conditionValue, $savedGroups, true);
            case '$exists':
                if (!$conditionValue) {
                    return $attributeValue === null;
                }
                return $attributeValue !== null;
            case '$type':
                return static::getType($attributeValue) === $conditionValue;
            case '$not':
                return !static::evalConditionValue($conditionValue, $attributeValue, $savedGroups);
            default:
                return false;
        }
    }

    /**
     * Version operators need two strings or two numbers. On a type mismatch
     * only $vne, the negation of $veq, matches.
     *
     * @param string $operator
     * @param mixed $attributeValue
     * @param mixed $conditionValue
     * @return bool
     */
    private static function evalVersion(string $operator, $attributeValue, $conditionValue): bool
    {
        if (!static::isOrderable($attributeValue, $conditionValue)) {
            return $operator === '$vne';
        }
        $actual = static::parseVersionString($attributeValue);
        $expected = static::parseVersionString($conditionValue);
        switch ($operator) {
            case '$veq':
                return $actual === $expected;
            case '$vne':
                return $actual !== $expected;
            case '$vgt':
                return $actual > $expected;
            case '$vgte':
                return $actual >= $expected;
            case '$vlt':
                return $actual < $expected;
            default:
                return $actual <= $expected;
        }
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
     * @param mixed $attributeValue
     * @param array<mixed> $conditionValue
     * @param bool $insensitive
     * @return bool
     */
    private static function isIn($attributeValue, array $conditionValue, bool $insensitive = false): bool
    {
        if (!is_array($attributeValue)) {
            $attributeValue = [$attributeValue];
        }

        foreach ($attributeValue as $actual) {
            foreach ($conditionValue as $expected) {
                if ($insensitive && is_string($actual) && is_string($expected)) {
                    if (strcasecmp($actual, $expected) === 0) {
                        return true;
                    }
                } elseif (static::isEqual($actual, $expected)) {
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
     * @return bool
     */
    private static function isInAll($attributeValue, $conditionValue, $savedGroups, bool $insensitive = false): bool
    {
        foreach ($conditionValue as $a) {
            $pass = false;
            foreach ($attributeValue as $b) {
                if (static::evalConditionValue($a, $b, $savedGroups, $insensitive)) {
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
     * @param mixed $pattern
     * @param mixed $value
     * @param bool $caseInsensitive
     * @return int|false
     */
    private static function regexMatch($pattern, $value, bool $caseInsensitive = false)
    {
        $modifiers = $caseInsensitive ? 'i' : '';
        return @preg_match('/' . $pattern . '/' . $modifiers, $value ?? '');
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
