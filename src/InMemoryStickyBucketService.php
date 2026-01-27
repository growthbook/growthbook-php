<?php

namespace Growthbook;


class InMemoryStickyBucketService extends StickyBucketService
{
    /** @var array<string, array<string,mixed>> */
    public $docs = [];

    /**
     * Sync version - direct access to data
     * @param string $attributeName
     * @param mixed $attributeValue
     * @return array<string,mixed>|null
     */
    protected function getAssignmentsSync(string $attributeName, $attributeValue): ?array
    {
        return $this->docs[$this->getKey($attributeName, $attributeValue)] ?? null;
    }

    /**
     * Sync version - direct data storage
     * @param array<string,mixed> $doc
     * @return void
     */
    protected function saveAssignmentsSync(array $doc): void
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
