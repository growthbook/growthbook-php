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

    /**
     * @param array{enabled?:bool,logger?:\Psr\Log\LoggerInterface,enableQueryStringOverride?:boolean} $options
    */
    public function __construct(array $options)
    {
        // Warn if any unknown options are passed
        $knownOptions = ["enabled","logger","enableQueryStringOverride"];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error('Unknown Config options: '.implode(", ", $unknownOptions), E_USER_NOTICE);
        }

        $this->enabled = $options["enabled"] ?? true;
        $this->logger = $options["logger"] ?? null;
        $this->enableQueryStringOverride = $options["enableQueryStringOverride"] ?? false;
        $this->url = $options["url"] ?? $_SERVER['REQUEST_URL'];
    }
}
