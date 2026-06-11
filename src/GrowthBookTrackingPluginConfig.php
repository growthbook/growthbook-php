<?php

namespace Growthbook;

class GrowthBookTrackingPluginConfig
{
    public const DEFAULT_INGESTOR_HOST = "https://us1.gb-ingest.com";
    public const DEFAULT_BATCH_SIZE    = 100;

    public string $ingestorHost;
    public int $batchSize;

    public function __construct(
        string $ingestorHost = self::DEFAULT_INGESTOR_HOST,
        int $batchSize = self::DEFAULT_BATCH_SIZE
    ) {
        $this->ingestorHost = rtrim($ingestorHost, "/");
        $this->batchSize    = max(1, $batchSize);
    }
}
