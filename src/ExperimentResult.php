<?php

namespace Growthbook;

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
     * @var boolean
     */
    public $hashUsed;
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
     * @var string|null
     */
    public $featureId;


    /**
     * @param InlineExperiment<T> $experiment
     * @param string $hashValue
     * @param int $variationIndex
     * @param bool $hashUsed
     */
    public function __construct(InlineExperiment $experiment, string $hashValue = "", int $variationIndex = -1, bool $hashUsed = false, string $featureId = null)
    {
        $inExperiment = true;
        // If the assigned variation is invalid, the user is not in the experiment and should get assigned the baseline
        $numVariations = count($experiment->variations);
        if ($variationIndex < 0 || $variationIndex >= $numVariations) {
            $variationIndex = 0;
            $inExperiment = false;
        }

        $this->inExperiment = $inExperiment;
        $this->hashUsed = $hashUsed;
        $this->variationId = $variationIndex;
        $this->value = $experiment->variations[$variationIndex];
        $this->hashAttribute = $experiment->hashAttribute ?? "id";
        $this->hashValue = $hashValue;
        $this->featureId = $featureId;
    }
}
