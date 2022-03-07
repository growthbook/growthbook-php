<?php

namespace GrowthBook;

/**
 * @template T
 */
class ExperimentResult
{
    /**
     * @var boolean
     */
    public $inExperiment;
    /**
     * @var int
     */
    public $variationId;
    /**
     * @var T
     */
    public $value;

    /**
     * @var string
     */
    public $hashAttribute;
    /**
     * @var string
     */
    public $hashValue;

    /**
     * @param InlineExperiment<T> $experiment
     * @param string $hashValue
     * @param int $variationIndex
     * @param bool $inExperiment
     */
    public function __construct(InlineExperiment $experiment, string $hashValue = "", int $variationIndex = 0, bool $inExperiment = false)
    {
        $numVariations = count($experiment->variations);
        if ($variationIndex < 0 || $variationIndex >= $numVariations) {
            $variationIndex = 0;
        }

        $this->inExperiment = $inExperiment;
        $this->variationId = $variationIndex;
        $this->value = $experiment->variations[$variationIndex];
        $this->hashAttribute = $experiment->hashAttribute ?? "id";
        $this->hashValue = $hashValue;
    }
}
