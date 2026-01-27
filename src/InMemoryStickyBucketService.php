<?php

namespace Growthbook;

class InMemoryStickyBucketService extends StickyBucketService
{
    /** @var array<string, array>  */
    public $docs = [];

    /**
     * @param string $attributeName
     * @param mixed $attributeValue
     * @param callable|null $completion function(array|null $doc, \Throwable|null $error): void
     * @return array<string,mixed>|null Returns result directly if no completion callback
     */
    public function getAssignments(string $attributeName, $attributeValue, ?callable $completion = null): ?array
    {
        if ($completion === null) {
            // Sync behavior
            return $this->docs[$this->getKey($attributeName, $attributeValue)] ?? null;
        }
        
        // Async behavior with completion callback
        try {
            $result = $this->docs[$this->getKey($attributeName, $attributeValue)] ?? null;
            $completion($result, null);
            return null;
        } catch (\Throwable $error) {
            $completion(null, $error);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $doc
     * @param callable|null $completion function(\Throwable|null $error): void
     * @return void
     */
    public function saveAssignments(array $doc, ?callable $completion = null): void
    {
        if ($completion === null) {
            // Sync behavior
            $this->docs[$this->getKey($doc['attributeName'], $doc['attributeValue'])] = $doc;
            return;
        }
        
        // Async behavior with completion callback
        try {
            $this->docs[$this->getKey($doc['attributeName'], $doc['attributeValue'])] = $doc;
            $completion(null);
        } catch (\Throwable $error) {
            $completion($error);
        }
    }

    /**
     * @return void
     */
    public function destroy(): void
    {
        $this->docs = [];
    }
}
