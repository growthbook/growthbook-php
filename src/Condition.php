<?php

namespace Growthbook;

class Condition
{
    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $condition
     * @return bool
     */
    public static function evalCondition(array $attributes, array $condition): bool
    {
        if (isset($condition['$or'])) {
            return static::evalOr($attributes, $condition['$or']);
        }
        if (isset($condition['$nor'])) {
            return !static::evalOr($attributes, $condition['$nor']);
        }
        if (isset($condition['$and'])) {
            return static::evalAnd($attributes, $condition['$and']);
        }
        if (isset($condition['$not'])) {
            return !static::evalCondition($attributes, $condition['$not']);
        }

        foreach ($condition as $key => $value) {
            if (!static::evalConditionValue($value, static::getPath($attributes, $key))) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed>[] $conditions
     * @return bool
     */
    private static function evalOr(array $attributes, array $conditions): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (static::evalCondition($attributes, $condition)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed>[] $conditions
     * @return bool
     */
    private static function evalAnd(array $attributes, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (!static::evalCondition($attributes, $condition)) {
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
     * @return bool
     */
    private static function evalConditionValue($conditionValue, $attributeValue): bool
    {
        if (is_array($conditionValue) && static::isOperatorObject($conditionValue)) {
            foreach ($conditionValue as $key => $value) {
                if (!static::evalOperatorCondition($key, $attributeValue, $value)) {
                    return false;
                }
            }
            return true;
        }

        if (is_string($conditionValue)) {
            $pattern = $conditionValue;
            if (!static::isValidRegex($pattern)) {
                $pattern = static::wrapRegexPattern($pattern);
            }
            if (!static::isValidRegex($pattern)) {
                error_log("Invalid regex pattern: $conditionValue");
                return false;
            }
            return preg_match($pattern, $attributeValue ?? '') === 1;
        }

        return json_encode($attributeValue) === json_encode($conditionValue);
    }
    private static function isValidRegex(string $pattern): bool
    {
        return @preg_match($pattern, '') !== false;
    }

    private static function wrapRegexPattern(string $pattern): string
    {
        if (preg_match('/^\/.*\/[imsxuADSUXJ]*$/', $pattern)) {
            return $pattern;
        }
        $escapedPattern = str_replace('/', '\\/', $pattern);
        return '/' . $escapedPattern . '/';
    }


    /**
     * @param array<string,mixed> $condition
     * @param mixed $attributeValue
     * @return bool
     */
    private static function elemMatch(array $condition, $attributeValue): bool
    {
        if (!is_array($attributeValue)) {
            return false;
        }

        foreach ($attributeValue as $item) {
            if (static::isOperatorObject($condition)) {
                if (static::evalConditionValue($condition, $item)) {
                    return true;
                }
            } elseif (static::evalCondition($item, $condition)) {
                return true;
            }
        }
        return false;
    }


    /**
     * @param mixed $val1
     * @param mixed $val2
     * @return int
     */
    private static function compare($val1, $val2): int
    {
        if ((is_int($val1) || is_float($val1)) && !(is_int($val2) || is_float($val2))) {
            if ($val2 === null) {
                $val2 = 0;
            } else {
                $val2 = (float)$val2;
            }
        }

        if ((is_int($val2) || is_float($val2)) && !(is_int($val1) || is_float($val1))) {
            if ($val1 === null) {
                $val1 = 0;
            } else {
                $val1 = (float)$val1;
            }
        }

        if ($val1 > $val2) {
            return 1;
        }
        if ($val1 < $val2) {
            return -1;
        }
        return 0;
    }

    /**
     * @param string $operator
     * @param mixed $attributeValue
     * @param mixed $conditionValue
     * @return bool
     */
    private static function evalOperatorCondition(string $operator, $attributeValue, $conditionValue): bool
    {
        switch ($operator) {
            case '$eq':
                return static::compare($attributeValue, $conditionValue) === 0;
            case '$ne':
                return static::compare($attributeValue, $conditionValue) !== 0;
            case '$lt':
                return static::compare($attributeValue, $conditionValue) < 0;
            case '$lte':
                return static::compare($attributeValue, $conditionValue) <= 0;
            case '$gt':
                return static::compare($attributeValue, $conditionValue) > 0;
            case '$gte':
                return static::compare($attributeValue, $conditionValue) >= 0;
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
                $pattern = $conditionValue;
                if (!static::isValidRegex($pattern)) {
                    $pattern = static::wrapRegexPattern($pattern);
                }
                if (!static::isValidRegex($pattern)) {
                    error_log("Invalid regex pattern: $conditionValue");
                    return false;
                }
                return preg_match($pattern, $attributeValue ?? '') === 1;

            case '$in':
                if (!is_array($conditionValue)) {
                    return false;
                }
                if (!is_array($attributeValue)) {
                    $attributeValue = [$attributeValue];
                }
                return array_intersect($attributeValue, $conditionValue) !== [];
            case '$nin':
                if (!is_array($conditionValue)) {
                    return false;
                }
                if (!is_array($attributeValue)) {
                    $attributeValue = [$attributeValue];
                }
                return array_intersect($attributeValue, $conditionValue) === [];
            case '$elemMatch':
                return static::elemMatch($conditionValue, $attributeValue);
            case '$size':
                if (!is_array($attributeValue)) {
                    return false;
                }
                return static::evalConditionValue($conditionValue, count($attributeValue));
            case '$all':
                if (!is_array($attributeValue) || !is_array($conditionValue)) {
                    return false;
                }
                foreach ($conditionValue as $a) {
                    $pass = false;
                    foreach ($attributeValue as $b) {
                        if (static::evalConditionValue($a, $b)) {
                            $pass = true;
                            break;
                        }
                    }
                    if (!$pass) {
                        return false;
                    }
                }
                return true;
            case '$exists':
                if (!$conditionValue) {
                    return $attributeValue === null;
                }
                return $attributeValue !== null;
            case '$type':
                return static::getType($attributeValue) === $conditionValue;
            case '$not':
                if (is_array($conditionValue) && static::isOperatorObject($conditionValue)) {
                    foreach ($conditionValue as $op => $val) {
                        if (static::evalOperatorCondition($op, $attributeValue, $val)) {
                            return false;
                        }
                    }
                    return true;
                } else {
                    return !static::evalConditionValue($conditionValue, $attributeValue);
                }

            default:
                return false;
        }
    }

    public static function parseVersionString(string $version): string
    {
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
}
