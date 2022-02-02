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
    /** @var string */
    public $url = null;
    /** @var array<string,mixed> */
    public $attributes = [];
    /** @var array<string,array> */
    public $features = [];

    /**
     * @param array{enabled?:bool,logger?:\Psr\Log\LoggerInterface,enableQueryStringOverride?:boolean,url?:string,attributes?:array<string,mixed>,features?:array<string,array>} $options
     */
    public function __construct(array $options)
    {
        // Warn if any unknown options are passed
        $knownOptions = ["enabled", "logger", "enableQueryStringOverride", "url", "attributes", "features"];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error('Unknown Config options: ' . implode(", ", $unknownOptions), E_USER_NOTICE);
        }

        $this->enabled = $options["enabled"] ?? true;
        $this->logger = $options["logger"] ?? null;
        $this->enableQueryStringOverride = $options["enableQueryStringOverride"] ?? false;
        $this->url = $options["url"] ?? $_SERVER['REQUEST_URI'] ?? null;
        $this->attributes = $options["attributes"] ?? [];
        $this->features = $options["features"] ?? [];
    }
}
