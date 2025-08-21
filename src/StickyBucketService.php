<?php

namespace Growthbook;

abstract class StickyBucketService
{
    /**
     * @param string $attributeName
     * @param string $attributeValue
     * @param callable|null $completion function(array|null $doc, \Throwable|null $error): void
     * @return array<string,mixed>|null Returns result directly if no completion callback
     */
    abstract public function getAssignments(string $attributeName, string $attributeValue, ?callable $completion = null): ?array;

    /**
     * @param array<string,mixed> $doc
     * @param callable|null $completion function(\Throwable|null $error): void
     * @return void
     */
    abstract public function saveAssignments(array $doc, ?callable $completion = null): void;

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
     * @param callable|null $completion function(array $docs, \Throwable|null $error): void
     * @return array<string, array> Returns result directly if no completion callback, empty array otherwise
     */
    public function getAllAssignments(array $attributes, ?callable $completion = null): array
    {
        if ($completion === null) {
            // Sync behavior
            $docs = [];
            foreach ($attributes as $attributeName => $attributeValue) {
                $doc = $this->getAssignmentsSync($attributeName, $attributeValue);
                if ($doc) {
                    $docs[$this->getKey($attributeName, $attributeValue)] = $doc;
                }
            }
            return $docs;
        }
        
        // Async behavior with completion callback
        try {
            $docs = [];
            foreach ($attributes as $attributeName => $attributeValue) {
                $doc = $this->getAssignmentsSync($attributeName, $attributeValue);
                if ($doc) {
                    $docs[$this->getKey($attributeName, $attributeValue)] = $doc;
                }
            }
            $completion($docs, null);
            return [];
        } catch (\Throwable $error) {
            $completion([], $error);
            return [];
        }
    }

    /**
     * Private sync method like Swift implementation
     * @param string $attributeName
     * @param string $attributeValue
     * @return array<string,mixed>|null
     */
    private function getAssignmentsSync(string $attributeName, string $attributeValue): ?array
    {
        return $this->getAssignments($attributeName, $attributeValue);
    }
}