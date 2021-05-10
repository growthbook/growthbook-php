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
     * @var int
     */
    public $variationId;
    /**
     * @var T
     */
    public $value;

    /**
     * @var null|Experiment<T>
     * @deprecated
     */
    public $experiment;
    /**
     * @var int
     * @deprecated
     */
    public $variation;

    /**
     * @param Experiment<T> $experiment
     * @param int $variation
     */
    public function __construct(Experiment $experiment, int $variation = -1)
    {
        $this->inExperiment = true;
        if ($variation < 0) {
            $this->inExperiment = false;
            $variation = 0;
        }

        $this->variationId = $variation;
        $this->value = $experiment->variations[$this->variationId];

        $this->experiment = $experiment;
        $this->variation = $variation;
    }
}
