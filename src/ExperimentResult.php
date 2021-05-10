<?php

namespace Growthbook;

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
     * @var mixed
     */
    public $value;

    /**
     * @var null|Experiment
     * @deprecated
     */
    public $experiment;
    /**
     * @var int
     * @deprecated
     */
    public $variation;

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
