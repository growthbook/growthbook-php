<?php

namespace Growthbook;

/**
 * Class StickyAssignmentDocument
 */
class StickyAssignmentDocument
{
    /**
     * @var string
     */
    private string $attributeName;

    /**
     * @var string
     */
    private string $attributeValue;

    /**
     * @var array<string, string>
     */
    private array $assignments;

    /**
     * StickyAssignmentDocument constructor.
     * @param string $attributeName
     * @param mixed $attributeValue
     * @param array<string,string>  $assignments
     */
    public function __construct(string $attributeName, $attributeValue, array $assignments)
    {
        $this->attributeName = $attributeName;
        $this->attributeValue = $attributeValue;
        $this->assignments = $assignments;
    }

    /**
     * @return string
     */
    public function getAttributeName(): string
    {
        return $this->attributeName;
    }

    /**
     * @return string
     */
    public function getAttributeValue(): string
    {
        return $this->attributeValue;
    }

    /**
     * @return array<string, string>
     */
    public function getAssignments(): array
    {
        return $this->assignments;
    }
}
