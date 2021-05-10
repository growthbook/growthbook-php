<?php

namespace Growthbook;

class Config
{
    /** @var bool */
    public $enabled = true;
    /** @var null|\Psr\Log\LoggerInterface */
    public $logger = null;
    /** @var bool */
    public $enableQueryStringOverride = false;

    /**
     * @param array{enabled?:bool,logger?:\Psr\Log\LoggerInterface,enableQueryStringOverride?:boolean} $options
    */
    public function __construct(array $options)
    {
        $this->enabled = $options["enabled"] ?? true;
        $this->logger = $options["logger"] ?? null;
        $this->enableQueryStringOverride = $options["enableQueryStringOverride"] ?? false;
    }
}
