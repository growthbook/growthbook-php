<?php

namespace Growthbook;

/**
 * Remote saved groups (growthbook/growthbook#7277).
 *
 * A remote saved group keeps its members outside the payload, which only says
 * which attribute the group is about: `{"type": "remote", "attributeKey": ...}`.
 * The app resolves which of them a user is in, and conditions read the ids from
 * the reserved `__gb_remoteGroupIds` attribute.
 *
 * @internal Used by Growthbook and Condition
 */
final class RemoteSavedGroups
{
    /**
     * The attribute conditions read a user's remote saved groups from. Internal
     * attributes start with `__gb_`, matching GrowthBook's back end.
     */
    public const ATTRIBUTE = '__gb_remoteGroupIds';

    /**
     * The payload's remote saved groups, as a resolver gets them. Only v2
     * payloads carry this metadata.
     *
     * @param array<string,mixed> $savedGroups
     * @return array<int,array{id:string,attributeKey:string,valuesFrom?:string}>
     */
    public static function fromSavedGroups(array $savedGroups): array
    {
        $groups = [];
        foreach ($savedGroups as $id => $entry) {
            // A remote entry without an attribute gives a resolver nothing to look
            // up, so its rules fail closed
            if (!is_array($entry) || ($entry['type'] ?? null) !== 'remote' || !is_string($entry['attributeKey'] ?? null)) {
                continue;
            }
            $group = ['id' => (string) $id, 'attributeKey' => $entry['attributeKey']];
            if (is_string($entry['valuesFrom'] ?? null) && $entry['valuesFrom'] !== '') {
                $group['valuesFrom'] = $entry['valuesFrom'];
            }
            $groups[] = $group;
        }
        return $groups;
    }

    /**
     * Asks $resolver which of $groups the user is in. Only the attributes those
     * groups use are passed, keyed by attributeKey. Throws what the resolver throws.
     *
     * @param array<string,mixed> $attributes
     * @param array<int,array{id:string,attributeKey:string,valuesFrom?:string}> $groups
     * @param callable(array<string,mixed>,array<int,array{id:string,attributeKey:string,valuesFrom?:string}>):array<string> $resolver
     * @return string[]
     */
    public static function resolve(array $attributes, array $groups, callable $resolver): array
    {
        $used = [];
        foreach ($groups as $group) {
            // attributeKey can be a dot-separated path
            $value = static::getPath($attributes, $group['attributeKey']);
            if ($value !== null) {
                $used[$group['attributeKey']] = $value;
            }
        }
        $ids = $resolver($used, $groups);
        if (!is_array($ids)) {
            throw new \UnexpectedValueException('A saved group resolver must return an array of group ids');
        }
        return array_values(array_map('strval', $ids));
    }

    /**
     * The attributes conditions see: $attributes, with the resolved remote group
     * ids (when set) as `__gb_remoteGroupIds`, over any value the attributes set
     * for it. Remote evaluation sends these too, so the server sees the same groups.
     *
     * @param array<string,mixed> $attributes
     * @param string[]|null $remoteGroupIds
     * @return array<string,mixed>
     */
    public static function withIds(array $attributes, ?array $remoteGroupIds): array
    {
        if ($remoteGroupIds === null) {
            return $attributes;
        }
        $attributes[self::ATTRIBUTE] = $remoteGroupIds;
        return $attributes;
    }

    /**
     * Whether the payload has remote groups but the user has no remote group ids,
     * so every rule using them will fail
     *
     * @param array<string,mixed> $attributes
     * @param string[]|null $remoteGroupIds
     * @param array<int,array{id:string,attributeKey:string,valuesFrom?:string}> $groups
     */
    public static function isMissingIds(array $attributes, ?array $remoteGroupIds, array $groups): bool
    {
        return $remoteGroupIds === null && !is_array($attributes[self::ATTRIBUTE] ?? null) && count($groups) > 0;
    }

    /**
     * @param array<string,mixed> $obj
     * @return mixed
     */
    private static function getPath(array $obj, string $path)
    {
        $current = $obj;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    }
}
