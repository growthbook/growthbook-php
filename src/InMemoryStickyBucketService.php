<?php

namespace Growthbook;

class InMemoryStickyBucketService extends StickyBucketService
{
    /** @var array<string, array>  */
    public $docs = [];

    /**
     * @param string $attributeName
     * @param mixed $attributeValue
     * @return array<string,mixed>|null
     */
    public function getAssignments(string $attributeName, $attributeValue): ?array
    {
        return $this->docs[$this->getKey($attributeName, $attributeValue)] ?? null;
    }

    /**
     * @param array<string, mixed> $doc
     * @return void
     */
    public function saveAssignments(array $doc): void
    {
        $this->docs[$this->getKey($doc['attributeName'], $doc['attributeValue'])] = $doc;
    }

    /**
     * @return void
     */
    public function destroy(): void
    {
        $this->docs = [];
    }
}