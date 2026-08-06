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

    /** @var bool  */
    public $stickyBucketUsed;

    /**
     * Contextual bandit leaf that supplied the weights, or -1 when the definition was found
     * but no leaf matched. Only set for a real exposure.
     *
     * @var int|null
     */
    public $leafId;

    /**
     * Variation weights the contextual bandit applied to this experiment. Only set for a real
     * exposure.
     *
     * @var float[]|null
     */
    public $variationWeights;

    /**
     * Version of the backend-computed bandit weights. Only set for a real exposure and when
     * the definition carries one.
     *
     * @var int|null
     */
    public $banditVersion;


    /**
     * @param InlineExperiment<T> $experiment
     * @param string $hashAttribute
     * @param string $hashValue
     * @param int $variationIndex
     * @param bool $hashUsed
     * @param string|null $featureId
     * @param float|null $bucket
     * @param bool $stickyBucketUsed
     */
    public function __construct(InlineExperiment $experiment, string $hashAttribute = 'id', string $hashValue = "", int $variationIndex = -1, bool $hashUsed = false, ?string $featureId = null, ?float $bucket = null, bool $stickyBucketUsed = false)
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
        $this->hashAttribute = $hashAttribute;
        $this->hashValue = $hashValue;
        $this->featureId = $featureId;
        $this->bucket = $bucket;
        $this->stickyBucketUsed = $stickyBucketUsed;

        // Contextual bandit metadata is reported only for a real exposure, so forced, QA-mode
        // and filtered-out assignments carry none.
        if ($experiment->contextualBandit && $hashUsed && $inExperiment) {
            $this->leafId = $experiment->contextualBandit->leafId;
            $this->variationWeights = $experiment->contextualBandit->variationWeights;
            $this->banditVersion = $experiment->contextualBandit->banditVersion;
        }

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
