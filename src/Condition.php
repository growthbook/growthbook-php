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

    /**
     * Compares two version strings according to semantic versioning rules.
     * @param string $version1
     * @param string $version2
     * @param string $operator The comparison operator to use, one of:
     *                         - "eq": Equal (=)
     *                         - "gt": Greater than (>)
     *                         - "gte": Greater than or equal (>=)
     *                         - "lt": Less than (<)
     *                         - "lte": Less than or equal (<=)
     * @return bool
     * @throws \InvalidArgumentException If an invalid operator is provided
     */
    public static function compareVersions(string $version1, string $version2, string $operator): bool
    {
        // Validate operator
        $validOperators = ['eq', 'gt', 'gte', 'lt', 'lte'];
        if (!in_array($operator, $validOperators)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid operator "%s". Valid operators are: %s',
                $operator,
                implode(', ', $validOperators)
            ));
        }

        // Parse and normalize versions
        $parseVersion = function (string $version) {
            // Remove 'v' prefix and build metadata
            $parts = preg_split('/[-.]/', preg_replace('/(^v|\+.*$)/', '', $version) ?? '');

            if (!is_array($parts)) {
                return false; // Couldn't parse
            }

            // Remove empty parts
            $parts = array_values(array_filter($parts, function ($part) {
                return $part !== '';
            }));

            // Pad numeric parts with zeros
            $parts = array_map(function ($part) {
                return preg_match('/^\d+$/', $part) ? str_pad($part, 5, "0", STR_PAD_LEFT) : $part;
            }, $parts);

            // Split into main version and prerelease
            $mainParts = array_slice($parts, 0, min(3, count($parts)));
            $prereleaseParts = array_slice($parts, 3);

            $mainVersion = implode('.', $mainParts);
            $prerelease = implode('.', $prereleaseParts);

            return [$mainVersion, $prerelease];
        };

        // Parse both versions
        $result1 = $parseVersion($version1);
        $result2 = $parseVersion($version2);

        // Handle parsing failures
        if ($result1 === false || $result2 === false) {
            return $version1 === $version2;
        }

        [$mainVersion1, $prerelease1] = $result1;
        [$mainVersion2, $prerelease2] = $result2;

        // Compare main versions first
        if ($mainVersion1 !== $mainVersion2) {
            switch ($operator) {
                case 'eq':
                    return false;
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

        // Main versions are equal, now compare prereleases

        // If prereleases are identical, simple comparison
        if ($prerelease1 === $prerelease2) {
            return in_array($operator, ['eq', 'gte', 'lte']);
        }

        // Special handling for empty prereleases (they are greater than any prerelease)
        // In SemVer: 1.0.0 > 1.0.0-alpha
        if ($prerelease1 === '' || $prerelease2 === '') {
            // Determine which is greater
            $isFirstGreater = $prerelease1 === '';

            // Apply comparison based on operator
            switch ($operator) {
                case 'eq':
                    return false; // Different prereleases cannot be equal
                case 'gt':
                    return $isFirstGreater; // First is greater if it has no prerelease
                case 'gte':
                    return $isFirstGreater; // Same logic as 'gt' since they can't be equal
                case 'lt':
                    return !$isFirstGreater; // First is less if it has a prerelease and second doesn't
                case 'lte':
                    return !$isFirstGreater; // Same logic as 'lt' since they can't be equal
            }
        }

        // Both have different prereleases, compare them directly
        switch ($operator) {
            case 'eq':
                return false; // Already checked equality above
            case 'gt':
                return $prerelease1 > $prerelease2;
            case 'gte':
                return $prerelease1 >= $prerelease2;
            case 'lt':
                return $prerelease1 < $prerelease2;
            case 'lte':
                return $prerelease1 <= $prerelease2;
        }

        // This should never be reached if all operators are handled
        return false;
    }
}
