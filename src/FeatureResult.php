<?php

namespace Growthbook;

/**
 * @template T
 */
class FeatureResult
{
    /**
     * @var T
     */
    public $value;
    /**
     * @var boolean
     */
    public $on;
    /**
     * @var boolean
     */
    public $off;
    /**
     * @var string
     */
    public $source;
    /**
     * @var null|Experiment
     */
    public $experiment;
    /**
     * @var null|ExperimentResult
     */
    public $experimentResult;

    /**
     * @param T $value
     * @param string $source
     * @param null|Experiment $experiment
     * @param null|ExperimentResult $experimentResult
     */
    public function __construct(mixed $value, string $source, ?Experiment $experiment = null, ?ExperimentResult $experimentResult = null) {
        $this->value = $value;
        $this->on = !!$value;
        $this->off = !$value;
        $this->source = $source;
        $this->experiment = $experiment;
        $this->experimentResult = $experimentResult;
    }
}
