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

        foreach ($condition as $key=>$value) {
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
        if (!count($conditions)) {
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
        foreach ($obj as $key=>$value) {
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
            foreach ($conditionValue as $key=>$value) {
                if (!static::evalOperatorCondition($key, $attributeValue, $value)) {
                    return false;
                }
            }
            return true;
        }

        return json_encode($attributeValue) === json_encode($conditionValue);
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
     * @param string $operator
     * @param mixed $attributeValue
     * @param mixed $conditionValue
     * @return bool
     */
    private static function evalOperatorCondition(string $operator, $attributeValue, $conditionValue): bool
    {
        switch ($operator) {
            case '$eq':
                return $attributeValue == $conditionValue;
            case '$ne':
                return $attributeValue != $conditionValue;
            case '$lt':
                return $attributeValue < $conditionValue;
            case '$lte':
                return $attributeValue <= $conditionValue;
            case '$gt':
                return $attributeValue > $conditionValue;
            case '$gte':
                return $attributeValue >= $conditionValue;
            case '$veq':
                return static::compareVersions($attributeValue, $conditionValue, 'eq');
            case '$vne':
                return !static::compareVersions($attributeValue, $conditionValue, 'eq');
            case '$vgt':
                return static::compareVersions($attributeValue, $conditionValue, 'gt');
            case '$vgte':
                return static::compareVersions($attributeValue, $conditionValue, 'gte');
            case '$vlt':
                return static::compareVersions($attributeValue, $conditionValue, 'lt');
            case '$vlte':
                return static::compareVersions($attributeValue, $conditionValue, 'lte');
            case '$regex':
                return @preg_match('/'.$conditionValue.'/', $attributeValue ?? '') === 1;
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
                return !static::evalConditionValue($conditionValue, $attributeValue);
            default:
                return false;
        }
    }

    public static function compareVersions(string $version1, string $version2, string $operator): bool
    {
        // Split the versions into parts, while dropping the build number
        $parts1 = preg_split('/[-.]/', preg_replace('/(^v|\+.*$)/', '', strtolower($version1)) ?? '');
        $parts2 = preg_split('/[-.]/', preg_replace('/(^v|\+.*$)/', '', strtolower($version2)) ?? '');

        // Remove any empty parts
        $parts1 = array_filter($parts1, function ($part) {
            return $part !== '';
        });
        $parts2 = array_filter($parts2, function ($part) {
            return $part !== '';
        });

        // Pad numeric parts with leading zeros
        $parts1 = array_map(function ($part) {
            if (preg_match('/(\d+)/', $part, $matches)) {
                $number = $matches[1];
                $padded = str_pad($number, 5, "0", STR_PAD_LEFT);
                return str_replace($number, $padded, $part);
            }
            return $part;
        }, $parts1);
        $parts2 = array_map(function ($part) {
            if (preg_match('/(\d+)/', $part, $matches)) {
                $number = $matches[1];
                $padded = str_pad($number, 5, "0", STR_PAD_LEFT);
                return str_replace($number, $padded, $part);
            }
            return $part;
        }, $parts2);

        // Create main version strings
        $mainVersion1 = implode('.', array_slice($parts1, 0, 3));
        $mainVersion2 = implode('.', array_slice($parts2, 0, 3));

        // Store prerelease info
        $prerelease1 = count($parts1) > 3 ? implode('.', array_slice($parts1, 3)) : '';
        $prerelease2 = count($parts2) > 3 ? implode('.', array_slice($parts2, 3)) : '';

        // If there are no prerelease strings, we can just compare the main versions strings
        if ($prerelease1 === '' && $prerelease2 === '') {
            switch ($operator) {
                case 'eq':
                    return $mainVersion1 === $mainVersion2;
                case 'gt':
                    return $mainVersion1 > $mainVersion2;
                case 'gte':
                    return $mainVersion1 >= $mainVersion2;
                case 'lt':
                    return $mainVersion1 < $mainVersion2;
                case 'lte':
                    return $mainVersion1 <= $mainVersion2;
            }
        }

        // If one of the prerelease strings is empty, consider it a lower version
        if ($prerelease1 === '' || $prerelease2 === '') {
            switch ($operator) {
                case 'eq':
                    return $mainVersion1 === $mainVersion2 && ($prerelease1 === '' ? true : false);
                case 'gt':
                    return $mainVersion1 > $mainVersion2 || ($prerelease1 === '' ? true : false);
                case 'gte':
                    return $mainVersion1 >= $mainVersion2 || ($prerelease1 === '' ? true : false);
                case 'lt':
                    return $mainVersion1 < $mainVersion2 || ($prerelease1 === '' ? false : true);
                case 'lte':
                    return $mainVersion1 <= $mainVersion2 || ($prerelease1 === '' ? false : true);
            }
        }

        // Then compare the prerelease strings
        switch ($operator) {
            case 'eq':
                return $mainVersion1 === $mainVersion2 || $prerelease1 === $prerelease2;
            case 'gt':
                return $mainVersion1 > $mainVersion2 || $prerelease1 > $prerelease2;
            case 'gte':
                return $mainVersion1 >= $mainVersion2 || $prerelease1 >= $prerelease2;
            case 'lt':
                return $mainVersion1 < $mainVersion2 || $prerelease1 < $prerelease2;
            case 'lte':
                return $mainVersion1 <= $mainVersion2 || $prerelease1 <= $prerelease2;
        }

        return true;
    }
}
