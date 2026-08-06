<?php

namespace Growthbook;

/**
 * A contextual bandit definition shipped in the feature payload under `contextualBandits`,
 * keyed by the `contextualBanditRef` a feature rule points at.
 *
 * A contextual bandit assigns per-segment variation weights: each `ContextualBanditContext`
 * ("leaf") carries a targeting condition and the weights to use when it matches. The weights
 * themselves are computed on the GrowthBook backend - the SDK only selects the matching leaf
 * and applies its weights before bucketing.
 */
class ContextualBanditDefinition
{
    /**
     * Monotonic version of the backend-computed weights, echoed onto ExperimentResult so
     * exposures can be attributed to the weight generation that produced them.
     *
     * @var null|int
     */
    public $banditVersion;

    /**
     * Ordered leaves. Leaves are evaluated top-to-bottom and the first whose condition matches
     * the user wins; if none match, the SDK falls back to the experiment's own or equal weights.
     *
     * @var ContextualBanditContext[]
     */
    public $contexts = [];

    /**
     * @param array<string,mixed> $definition
     */
    public function __construct(array $definition)
    {
        $banditVersion = $definition["banditVersion"] ?? null;
        if (is_int($banditVersion)) {
            $this->banditVersion = $banditVersion;
        } elseif (is_numeric($banditVersion)) {
            $this->banditVersion = (int) $banditVersion;
        }

        $contexts = $definition["contexts"] ?? null;
        if (is_array($contexts)) {
            foreach ($contexts as $context) {
                if ($context instanceof ContextualBanditContext) {
                    $this->contexts[] = $context;
                } elseif (is_array($context)) {
                    $this->contexts[] = new ContextualBanditContext($context);
                }
            }
        }
    }
}
