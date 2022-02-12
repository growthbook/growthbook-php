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
     * @var null|InlineExperiment<T>
     */
    public $experiment;
    /**
     * @var null|ExperimentResult<T>
     */
    public $experimentResult;

    /**
     * @param T $value
     * @param string $source
     * @param null|InlineExperiment<T> $experiment
     * @param null|ExperimentResult<T> $experimentResult
     */
    public function __construct($value, string $source, ?InlineExperiment $experiment = null, ?ExperimentResult $experimentResult = null)
    {
        $this->value = $value;
        $this->on = !!$value;
        $this->off = !$value;
        $this->source = $source;
        $this->experiment = $experiment;
        $this->experimentResult = $experimentResult;
    }
}
