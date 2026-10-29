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
     * @var null|string
     */
    public $ruleId;

    /**
     * @param T $value
     * @param string $source
     * @param null|InlineExperiment<T> $experiment
     * @param null|ExperimentResult<T> $experimentResult
     * @param null|string $ruleId
     */
    public function __construct($value, string $source, ?InlineExperiment $experiment = null, ?ExperimentResult $experimentResult = null, ?string $ruleId = null)
    {
        $this->value = $value;
        $this->on = static::isTruthy($value);
        $this->off = !$this->on;
        $this->source = $source;
        $this->experiment = $experiment;
        $this->experimentResult = $experimentResult;
        $this->ruleId = $ruleId;
    }

    /**
     * JS truthiness: only null, false, 0, NaN and "" are off. Unlike in PHP,
     * "0" and an empty array are on.
     *
     * @param mixed $value
     * @return bool
     */
    private static function isTruthy($value): bool
    {
        if (is_float($value)) {
            return $value != 0 && !is_nan($value);
        }
        return $value !== null && $value !== false && $value !== 0 && $value !== "";
    }
}
