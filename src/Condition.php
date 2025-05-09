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
    private static function evalConditionValue($conditionValue, $attributeValue, array $savedGroups): bool
    {
        if ($conditionValue === null) {
            return $attributeValue === null;
        }

        $type = static::getType($conditionValue);

        switch ($type) {
            case 'string':
            case 'number':
            case 'boolean':
                return static::isMatrchingPrimitive($conditionValue, $attributeValue);
            case 'array':
                return is_array($attributeValue) && static::toJson($conditionValue) === static::toJson($attributeValue);
            case 'object':
                if (static::isOperatorObject($conditionValue)) {
                    foreach ($conditionValue as $key => $value) {
                        if (!static::evalOperatorCondition($key, $attributeValue, $value, $savedGroups)) {
                            return false;
                        }
                    }
                    return true;
                }
                return is_array($attributeValue) && static::toJson($conditionValue) === static::toJson($attributeValue);

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
     * @param mixed $val1
     * @param mixed $val2
     * @return int
     */
    private static function compare($val1, $val2): int
    {
        if (is_bool($val1) || is_bool($val2)) {
            return $val1 !== null && $val2 !== null && !!$val1 === !!$val2 ? 0 : 1;
        }

        if ((is_int($val1) || is_float($val1)) && !(is_int($val2) || is_float($val2))) {
            if ($val2 === null) {
                $val2 = 0;
            } else {
                $val2 = (float) $val2;
            }
        }

        if ((is_int($val2) || is_float($val2)) && !(is_int($val1) || is_float($val1))) {
            if ($val1 === null) {
                $val1 = 0;
            } else {
                $val1 = (float) $val1;
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
     * @param mixed $savedGroups
     * @return bool
     */
    private static function evalOperatorCondition(string $operator, $attributeValue, $conditionValue, $savedGroups): bool
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
                return @preg_match('/' . $conditionValue . '/', $attributeValue ?? '') === 1;
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
                foreach ($conditionValue as $a) {
                    $pass = false;
                    foreach ($attributeValue as $b) {
                        if (static::evalConditionValue($a, $b, $savedGroups)) {
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
                return !static::evalConditionValue($conditionValue, $attributeValue, $savedGroups);
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

    private static function isMatrchingPrimitive(mixed $conditionValue, mixed $attributeValue): bool
    {
        if ($attributeValue === null) {
            return false;
        }

        if (gettype($conditionValue) !== gettype($attributeValue)) {
            return false;
        }

        return $conditionValue === $attributeValue;
    }

    private static function toJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return 'null';
        }

        return $json;
    }
}
