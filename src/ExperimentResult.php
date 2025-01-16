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
     * @var string
     */
    public $key;
    /**
     * @var float|null
     */
    public $bucket;
    /**
     * @var string|null
     */
    public $name;
    /**
     * @var boolean
     */
    public $passthrough;


    /**
     * @param InlineExperiment<T> $experiment
     * @param string $hashValue
     * @param int $variationIndex
     * @param bool $hashUsed
     * @param string|null $featureId
     * @param float|null $bucket
     */
    public function __construct(InlineExperiment $experiment, string $hashValue = "", int $variationIndex = -1, bool $hashUsed = false, ?string $featureId = null, ?float $bucket = null)
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
        $this->bucket = $bucket;

        $this->key = "" . $variationIndex;
        if ($experiment->meta) {
            $meta = $experiment->meta[$variationIndex] ?? null;
            if ($meta) {
                if (array_key_exists("key", $meta)) {
                    $this->key = $meta["key"];
                }
                if (array_key_exists("name", $meta)) {
                    $this->name = $meta["name"];
                }
                if (array_key_exists("passthrough", $meta)) {
                    $this->passthrough = $meta["passthrough"];
                }
            }
        }
    }
}
