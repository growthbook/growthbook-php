<?php

namespace Growthbook;

interface Plugin
{
    public function initialize(string $clientKey): void;

    /**
     * @param InlineExperiment<mixed> $experiment
     * @param ExperimentResult<mixed> $result
     */
    public function onExperimentViewed(InlineExperiment $experiment, ExperimentResult $result): void;

    /**
     * @param FeatureResult<mixed> $result
     */
    public function onFeatureEvaluated(string $featureKey, FeatureResult $result): void;

    public function close(): void;
}
