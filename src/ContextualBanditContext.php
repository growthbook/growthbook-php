<?php

namespace Growthbook;

/**
 * A single leaf of a `ContextualBanditDefinition`: a targeting condition paired with the
 * backend-computed variation weights to apply when that condition matches the user.
 *
 * Part of the read-only contextual bandit payload - leaves are decoded from the feature API
 * response and never mutated during evaluation.
 */
class ContextualBanditContext
{
    /**
     * Identifier of this leaf within the bandit definition. Surfaced on ExperimentResult so
     * exposures can be attributed back to the segment that produced the weights.
     *
     * @var null|int
     */
    public $leafId;

    /**
     * Targeting condition evaluated against the user's attributes. A null or empty condition
     * matches every user.
     *
     * @var null|array<string,mixed>
     */
    public $condition;

    /**
     * Variation weights to apply when this leaf matches. Must align with the experiment's
     * variation count and, like any weights, sum to 1.
     *
     * @var null|float[]
     */
    public $weights;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(array $context)
    {
        $leafId = $context["leafId"] ?? null;
        if (is_int($leafId)) {
            $this->leafId = $leafId;
        } elseif (is_numeric($leafId)) {
            $this->leafId = (int) $leafId;
        }

        $condition = $context["condition"] ?? null;
        if (is_array($condition)) {
            $this->condition = $condition;
        }

        $weights = $context["weights"] ?? null;
        if (is_array($weights)) {
            $floats = [];
            foreach ($weights as $weight) {
                if (!is_numeric($weight)) {
                    // A malformed weight makes the whole list unusable - fall back to the
                    // experiment's own weights instead of shipping a shorter list, which
                    // getBucketRanges would silently turn into equal weights.
                    $floats = null;
                    break;
                }
                $floats[] = (float) $weight;
            }
            $this->weights = $floats;
        }
    }
}
