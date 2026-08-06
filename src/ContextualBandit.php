<?php

namespace Growthbook;

/**
 * The outcome of resolving a `ContextualBanditDefinition` for a given user: the selected leaf
 * and the weights that were applied to the experiment.
 *
 * Attached to the experiment during evaluation and, when the user is hash-bucketed into the
 * experiment, copied onto ExperimentResult for tracking.
 *
 * A `leafId` of -1 means no leaf matched and the experiment fell back to its own or equal weights.
 */
class ContextualBandit implements \JsonSerializable
{
    /** Sentinel leafId recorded when a definition was found but no leaf matched the user. */
    public const FALLBACK_LEAF_ID = -1;

    /**
     * The matched leaf's id, or -1 when no leaf matched and fallback weights were used.
     *
     * @var null|int
     */
    public $leafId;

    /**
     * The weights actually applied to the experiment for this user.
     *
     * @var float[]
     */
    public $variationWeights;

    /**
     * The bandit version of the definition that produced these weights.
     *
     * @var null|int
     */
    public $banditVersion;

    /**
     * @param null|int $leafId
     * @param float[]  $variationWeights
     * @param null|int $banditVersion
     */
    public function __construct(?int $leafId, array $variationWeights, ?int $banditVersion = null)
    {
        $this->leafId = $leafId;
        $this->variationWeights = $variationWeights;
        $this->banditVersion = $banditVersion;
    }

    /**
     * Unset fields are omitted rather than serialized as null, matching the reference
     * TypeScript SDK where a definition without a `banditVersion` produces a result
     * without the key.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [];
        if ($this->leafId !== null) {
            $data["leafId"] = $this->leafId;
        }
        $data["variationWeights"] = $this->variationWeights;
        if ($this->banditVersion !== null) {
            $data["banditVersion"] = $this->banditVersion;
        }

        return $data;
    }
}
