<?php

namespace Growthbook;

class InMemoryStickyBucketService extends StickyBucketService
{
    public array $docs = [];

    /**
     * @param string $attributeName
     * @param string $attributeValue
     * @return StickyAssignmentDocument|null
     */
    public function getAssignments(string $attributeName, string $attributeValue): ?StickyAssignmentDocument
    {
        return $this->docs[$this->getKey($attributeName, $attributeValue)] ?? null;
    }

    /**
     * @param StickyAssignmentDocument $doc
     * @return void
     */
    public function saveAssignments(StickyAssignmentDocument $doc): void
    {
        $this->docs[$this->getKey($doc->getAttributeName(), $doc->getAttributeValue())] = $doc;
    }

    /**
     * @return void
     */
    public function destroy(): void
    {
        $this->docs = [];
    }
}