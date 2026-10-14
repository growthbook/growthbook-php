<?php

namespace Growthbook;

class GrowthBookTrackingPluginConfig
{
    public const DEFAULT_INGESTOR_HOST = "https://us1.gb-ingest.com";
    public const DEFAULT_BATCH_SIZE    = 100;

    public string $ingestorHost;
    public int $batchSize;

    /**
     * Whether "Feature Evaluated" events are sent. Turning it off leaves experiment exposures
     * and custom events untouched, so an app that only wants to cut feature-usage volume does
     * not lose its experiment data.
     */
    public bool $enableFeatureUsageEvents;

    public function __construct(
        string $ingestorHost = self::DEFAULT_INGESTOR_HOST,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        bool $enableFeatureUsageEvents = true
    ) {
        $this->ingestorHost             = rtrim($ingestorHost, "/");
        $this->batchSize                = max(1, $batchSize);
        $this->enableFeatureUsageEvents = $enableFeatureUsageEvents;
    }
}
