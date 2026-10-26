<?php

namespace Growthbook;

class Condition
{
    /**
     * Keys a condition can have that aren't attributes, besides $not, which is
     * also an operator on a value
     */
    private const CONDITION_OPERATORS = ['$or', '$nor', '$and', '$savedGroup'];

    /**
     * Saved group ids already being resolved, to stop cycles. Restored after
     * each group, so sibling groups don't see each other's ids.
     *
     * @var array<string,bool>
     */
    private static $visited = [];

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $savedGroups
     * @return bool
     */
    public static function evalCondition(array $attributes, array $condition, array $savedGroups): bool
    {
        return static::evalConditionWithContext($attributes, $condition, $savedGroups) === true;
    }

    /**
     * Partially evaluates a condition when only some attributes are known, e.g.
     * on a server. Attributes missing from $attributes are unknown. Returns a
     * boolean once the result can't depend on them, otherwise the condition
     * minus every branch that is already decided.
     *
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $savedGroups
     * @return array<string,mixed>|bool
     */
    public static function pruneCondition(array $attributes, array $condition, array $savedGroups = []): array|bool
    {
        // One that throws, such as a malformed one, stays as written. Evaluating
        // it fails as a whole, so no part of it can be decided.
        try {
            return static::pruneEntries($attributes, $condition, $savedGroups);
        } catch (\Throwable $e) {
            return $condition;
        }
    }

    /**
     * Prunes the saved group a reference points to. Returns its result once
     * decided, null when it can't be, otherwise its condition minus every
     * branch that is already decided.
     *
     * @internal Used by Growthbook::reducePayload
     * @param array<string,mixed> $attributes
     * @param mixed $reference
     * @param array<string,mixed> $savedGroups
     * @return array<string,mixed>|bool|null
     */
    public static function pruneSavedGroup(array $attributes, $reference, array $savedGroups): array|bool|null
    {
        // A malformed group can't be decided, so it stays as written
        try {
            return static::evalSavedGroup($attributes, $reference, $savedGroups, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param mixed $obj
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $savedGroups
     * @return array<string,mixed>|bool
     */
    private static function pruneEntries($obj, array $condition, array $savedGroups): array|bool
    {
        $out = [];
        foreach ($condition as $key => $value) {
            switch ($key) {
                case '$or':
                case '$nor':
                case '$and':
                    $r = static::pruneList($obj, $value, $savedGroups, $key !== '$and');
                    break;
                case '$not':
                    $r = static::pruneEntries($obj, $value, $savedGroups);
                    break;
                case '$savedGroup':
                    $r = static::evalSavedGroup($obj, $value, $savedGroups, true);
                    // Keep an undecided reference as is rather than inlining the group
                    if (!is_bool($r)) {
                        $r = null;
                    }
                    break;
                default:
                    // null when the attribute is unknown, or the value uses an
                    // operator this SDK doesn't support, which the client's SDK may
                    $r = static::isKnown($obj, (string) $key)
                        ? static::evalConditionValue($value, static::getPath($obj, (string) $key), $savedGroups)
                        : null;
            }
            // $not and $nor pass when what they hold fails
            if (($key === '$not' || $key === '$nor') && is_bool($r)) {
                $r = !$r;
            }

            // One failing entry fails the whole condition, and a passing one is dropped
            if ($r === false) {
                return false;
            }
            // null is an undecided leaf, which stays as written
            if ($r === null) {
                $out[$key] = $value;
            } elseif ($r !== true) {
                $out[$key] = $r;
            }
        }
        return count($out) ? $out : true;
    }

    /**
     * Prunes an $or (or an $and) list. Returns the conditions still undecided,
     * or the list's result once there are none.
     *
     * @param mixed $obj
     * @param array<string,mixed>[] $conditions
     * @param array<string,mixed> $savedGroups
     * @param bool $or
     * @return array<array<string,mixed>>|bool
     */
    private static function pruneList($obj, array $conditions, array $savedGroups, bool $or): array|bool
    {
        $out = [];
        foreach ($conditions as $condition) {
            $r = static::pruneEntries($obj, $condition, $savedGroups);
            // One passing condition decides an $or, and one failing condition an $and
            if ($or && $r === true) {
                return true;
            }
            if (!$or && $r === false) {
                return false;
            }
            if (!is_bool($r)) {
                $out[] = $r;
            }
        }
        if (count($out)) {
            return $out;
        }
        // Every condition failed an $or, or passed an $and. Like evalOr, an
        // empty $or passes.
        return $or ? !count($conditions) : true;
    }

    /**
     * A nested path is unknown, since the server may only have part of the object
     *
     * @param mixed $obj
     * @param string $path
     * @return bool
     */
    private static function isKnown($obj, string $path): bool
    {
        return strpos($path, '.') === false && is_array($obj) && array_key_exists($path, $obj);
    }

    /**
     * Evaluation helpers return true or false, or null when the result depends
     * on an operator or saved group type this SDK doesn't support. The public
     * methods treat null as failing.
     *
     * @param mixed $obj
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $savedGroups
     * @return bool|null
     */
    private static function evalConditionWithContext($obj, array $condition, array $savedGroups): ?bool
    {
        $result = true;
        foreach ($condition as $key => $value) {
            switch ($key) {
                case '$or':
                    $r = static::evalOr($obj, $value, $savedGroups);
                    break;
                case '$nor':
                    $r = static::not(static::evalOr($obj, $value, $savedGroups));
                    break;
                case '$and':
                    $r = static::evalAnd($obj, $value, $savedGroups);
                    break;
                case '$not':
                    $r = static::not(static::evalConditionWithContext($obj, $value, $savedGroups));
                    break;
                case '$savedGroup':
                    /** @var bool|null $r */
                    $r = static::evalSavedGroup($obj, $value, $savedGroups);
                    break;
                default:
                    $r = static::evalConditionValue($value, static::getPath($obj, (string) $key), $savedGroups);
            }
            // One failing entry decides it, wherever an unsupported one is
            if ($r === false) {
                return false;
            }
            if ($r === null) {
                $result = null;
            }
        }
        return $result;
    }

    /**
     * Resolves a `$savedGroup` reference. Anything unrecognized matches nobody.
     *
     * With $prune, an attribute missing from $obj is unknown: a list entry on it
     * is null, and a condition entry is pruned rather than evaluated.
     *
     * @param mixed $obj
     * @param mixed $reference
     * @param array<string,mixed> $savedGroups
     * @param bool $prune
     * @return array<string,mixed>|bool|null
     */
    private static function evalSavedGroup($obj, $reference, array $savedGroups, bool $prune = false): array|bool|null
    {
        if (!is_array($reference) || static::isList($reference)) {
            return false;
        }

        $id = $reference['id'] ?? null;
        if (!is_string($id) || isset(self::$visited[$id])) {
            return false;
        }
        // Falling back to the entry's attribute would check a different population
        if (array_key_exists('attributeKey', $reference) && !is_string($reference['attributeKey'])) {
            return false;
        }

        $entry = $savedGroups[$id] ?? null;
        // Absent, or a v1 bare array
        if (!is_array($entry) || static::isList($entry)) {
            return false;
        }

        $type = $entry['type'] ?? null;
        if ($type === 'list') {
            // The override wins over the entry's own attribute
            $key = $reference['attributeKey'] ?? ($entry['attributeKey'] ?? null);
            if (!is_string($key)) {
                return false;
            }
            $values = $entry['values'] ?? null;
            if (!is_array($values) || !static::isList($values)) {
                return false;
            }
            if ($prune && !static::isKnown($obj, $key)) {
                return null;
            }
            return static::isIn(static::getPath($obj, $key), $values, false);
        }

        if ($type === 'condition') {
            // A condition group has no single attribute, so any override is ignored
            $condition = $entry['condition'] ?? null;
            if (!is_array($condition)) {
                return false;
            }
            $visited = self::$visited;
            self::$visited[$id] = true;
            try {
                return $prune
                    ? static::pruneEntries($obj, $condition, $savedGroups)
                    : static::evalConditionWithContext($obj, $condition, $savedGroups);
            } finally {
                self::$visited = $visited;
            }
        }

        // A group type added after this SDK was built. The client SDK may support it.
        return null;
    }

    /**
     * @param bool|null $result
     * @return bool|null
     */
    private static function not(?bool $result): ?bool
    {
        return $result === null ? null : !$result;
    }

    /**
     * @param mixed $obj
     * @param array<string,mixed>[] $conditions
     * @param array<string,mixed> $savedGroups
     * @return bool|null
     */
    private static function evalOr($obj, array $conditions, array $savedGroups): ?bool
    {
        if (!count($conditions)) {
            return true;
        }

        $result = false;
        foreach ($conditions as $condition) {
            $r = static::evalConditionWithContext($obj, $condition, $savedGroups);
            if ($r === true) {
                return true;
            }
            if ($r === null) {
                $result = null;
            }
        }
        return $result;
    }

    /**
     * @param mixed $obj
     * @param array<string,mixed>[] $conditions
     * @param array<string,mixed> $savedGroups
     * @return bool|null
     */
    private static function evalAnd($obj, array $conditions, array $savedGroups): ?bool
    {
        $result = true;
        foreach ($conditions as $condition) {
            $r = static::evalConditionWithContext($obj, $condition, $savedGroups);
            if ($r === false) {
                return false;
            }
            if ($r === null) {
                $result = null;
            }
        }
        return $result;
    }

    /**
     * If every key starts with '$'. An empty object has no operators, so it passes.
     *
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
     * @param mixed $obj
     * @param string $path
     * @return mixed
     */
    private static function getPath($obj, string $path)
    {
        $current = $obj;
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
     * @return bool|null
     */
    private static function evalConditionValue($conditionValue, $attributeValue, array $savedGroups, bool $insensitive = false): ?bool
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
                // JSON decoding can't tell {} from [], and no condition compares
                // against an empty array, so an empty one is an object without
                // operators, which passes
                if ($conditionValue === []) {
                    return true;
                }
                return static::getType($attributeValue) === $type && static::toJson($conditionValue) === static::toJson($attributeValue);
            case 'object':
                if (static::isOperatorObject($conditionValue)) {
                    $result = true;
                    foreach ($conditionValue as $key => $value) {
                        $r = static::evalOperatorCondition((string) $key, $attributeValue, $value, $savedGroups);
                        if ($r === false) {
                            return false;
                        }
                        if ($r === null) {
                            $result = null;
                        }
                    }
                    return $result;
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
     * @return bool|null
     */
    private static function elemMatch(array $condition, $attributeValue, array $savedGroups): ?bool
    {
        if (!is_array($attributeValue)) {
            return false;
        }

        // Operators test each element itself. A condition, including one that only
        // uses $or, $and, $nor, or $savedGroup, tests each element's fields.
        $onValue = static::isOperatorObject($condition)
            && !array_intersect(array_map('strval', array_keys($condition)), self::CONDITION_OPERATORS);

        $result = false;
        foreach ($attributeValue as $item) {
            // Only skip null elements; falsy values like 0, "" and false are valid
            if ($item === null) {
                continue;
            }
            $r = $onValue
                ? static::evalConditionValue($condition, $item, $savedGroups)
                : static::evalConditionWithContext($item, $condition, $savedGroups);
            if ($r === true) {
                return true;
            }
            if ($r === null) {
                $result = null;
            }
        }
        return $result;
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
     * @return bool|null
     */
    private static function evalOperatorCondition(string $operator, $attributeValue, $conditionValue, $savedGroups): ?bool
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
            case '$notInGroup':
                $known = (is_string($conditionValue) || is_int($conditionValue))
                    && array_key_exists($conditionValue, $savedGroups);
                $entry = $known ? $savedGroups[$conditionValue] : null;
                if (static::isNewGroupType($entry)) {
                    return null;
                }
                // No values means matching nobody. An empty list would pass everyone.
                $values = static::getSavedGroupArrayValues($known, $entry);
                if ($values === null) {
                    return false;
                }
                $found = static::isIn($attributeValue, $values, false);
                return $operator === '$inGroup' ? $found : !$found;
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
                return static::not(static::evalConditionValue($conditionValue, $attributeValue, $savedGroups));
            default:
                // Keys this SDK knows, but on a condition rather than on a value, where
                // they never match. Any other operator is one this SDK doesn't support.
                return in_array($operator, self::CONDITION_OPERATORS, true) ? false : null;
        }
    }

    /**
     * The values of a saved group: the legacy bare array, or the values of a
     * typed "list" entry. Null for a group that has no values to check against.
     *
     * @param bool $known Whether the id is in the payload at all
     * @param mixed $entry
     * @return array<mixed>|null
     */
    private static function getSavedGroupArrayValues(bool $known, $entry): ?array
    {
        // An unknown id has always behaved like an empty list. A malformed entry
        // has not, and must not: $notInGroup would pass everyone.
        if (!$known) {
            return [];
        }
        if (is_array($entry) && static::isList($entry)) {
            return $entry;
        }
        if (is_array($entry) && ($entry['type'] ?? null) === 'list') {
            $values = $entry['values'] ?? null;
            return is_array($values) && static::isList($values) ? $values : null;
        }
        // A condition group, a malformed entry, or an unknown type
        return null;
    }

    /**
     * A saved group type added after this SDK was built
     *
     * @param mixed $entry
     * @return bool
     */
    private static function isNewGroupType($entry): bool
    {
        return is_array($entry)
            && !static::isList($entry)
            && ($entry['type'] ?? null) !== 'list'
            && ($entry['type'] ?? null) !== 'condition';
    }

    /**
     * @param array<mixed> $value
     * @return bool
     */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
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
     * Whether the value, or any element of an array value, is in the list
     *
     * @internal Used by Growthbook::reducePayload
     * @param mixed $attributeValue
     * @param array<mixed> $conditionValue
     * @param bool $insensitive
     * @return bool
     */
    public static function isIn($attributeValue, array $conditionValue, bool $insensitive = false): bool
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
     * @return bool|null
     */
    private static function isInAll($attributeValue, $conditionValue, $savedGroups, bool $insensitive = false): ?bool
    {
        $result = true;
        foreach ($conditionValue as $a) {
            $pass = false;
            foreach ($attributeValue as $b) {
                $r = static::evalConditionValue($a, $b, $savedGroups, $insensitive);
                if ($r === true) {
                    $pass = true;
                    break;
                }
                if ($r === null) {
                    $pass = null;
                }
            }
            if ($pass === false) {
                return false;
            }
            if ($pass === null) {
                $result = null;
            }
        }
        return $result;
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
        // A pattern that isn't a string, such as an array, never matches
        if (!is_string($pattern)) {
            return false;
        }
        $modifiers = $caseInsensitive ? 'i' : '';
        return @preg_match('/' . static::escapeRegexDelimiter($pattern) . '/' . $modifiers, $value ?? '');
    }

    /**
     * Escapes every unescaped "/" so the pattern can be wrapped in "/" delimiters.
     *
     * @param string $pattern
     * @return string
     */
    private static function escapeRegexDelimiter(string $pattern): string
    {
        $escaped = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $escaped .= $char . $pattern[++$i];
            } elseif ($char === '/') {
                $escaped .= '\\/';
            } else {
                $escaped .= $char;
            }
        }
        return $escaped;
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
