<?php

namespace Growthbook;

abstract class StickyBucketService
{
    /**
     * @param string $attributeName
     * @param string $attributeValue
     * @return array<string,mixed>|null
     */
    abstract public function getAssignments(string $attributeName, string $attributeValue): ?array;

    /**
     * @param array<string,mixed> $doc
     * @return void
     */
    abstract public function saveAssignments(array $doc): void;

    /**
     * @param string $attributeName
     * @param string $attributeValue
     * @return string
     */
    public function getKey(string $attributeName, string $attributeValue): string
    {
        return "{$attributeName}||{$attributeValue}";
    }

    /**
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     */
    public function getAllAssignments(array $attributes): array
    {
        $docs = [];
        foreach ($attributes as $attributeName => $attributeValue) {
            $doc = $this->getAssignments($attributeName, $attributeValue);
            if ($doc) {
                $docs[$this->getKey($attributeName, $attributeValue)] = $doc;
            }
        }
        return $docs;
    }
}
