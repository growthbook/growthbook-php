<?php

namespace Growthbook;

interface Plugin
{
    public function initialize(string $clientKey): void;

    /**
     * @param InlineExperiment<mixed> $experiment
     * @param ExperimentResult<mixed> $result
     * @param array<string,mixed>     $attributes Current user attributes at the time of evaluation
     */
    public function onExperimentViewed(InlineExperiment $experiment, ExperimentResult $result, array $attributes): void;

    /**
     * @param FeatureResult<mixed> $result
     * @param array<string,mixed>  $attributes Current user attributes at the time of evaluation
     */
    public function onFeatureEvaluated(string $featureKey, FeatureResult $result, array $attributes): void;

    public function close(): void;
}
