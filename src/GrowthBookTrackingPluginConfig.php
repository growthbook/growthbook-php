<?php

namespace Growthbook;

class GrowthBookTrackingPluginConfig
{
    public const DEFAULT_INGESTOR_HOST     = "https://us1.gb-ingest.com";
    public const DEFAULT_BATCH_SIZE        = 100;
    public const DEFAULT_DEDUPE_CACHE_SIZE = 1000;

    /** Headers the plugin sets itself and a caller may not replace. */
    private const RESERVED_INGESTOR_HEADERS = ["content-type", "user-agent"];

    public string $ingestorHost;
    public int $batchSize;

    /**
     * Master switch. When off the plugin accepts nothing at all - no exposures, no feature
     * usage, no custom events - which is the cheapest way to silence it in an environment
     * where the SDK itself must keep working.
     */
    public bool $enable;

    /**
     * Whether "Feature Evaluated" events are sent. Turning it off leaves experiment exposures
     * and custom events untouched, so an app that only wants to cut feature-usage volume does
     * not lose its experiment data.
     */
    public bool $enableFeatureUsageEvents;

    /**
     * Number of recently sent events remembered for deduplication. Handles of one instance keep
     * their own dedupe state, so without this the same evaluation reaches the ingestor once per
     * handle. 0 disables it.
     */
    public int $dedupeCacheSize;

    /**
     * Attribute keys that take part in the deduplication signature. Null - the default - uses
     * every attribute, which is what stops one user's event from suppressing another's. Narrow
     * it to the identifying attributes when volatile ones (timestamps, request ids) would
     * otherwise make every event unique.
     *
     * @var array<string>|null
     */
    public ?array $dedupeKeyAttributes;

    /**
     * Receives each event before it is queued; returning false drops it. This is the hook for
     * rejecting or redacting what must not leave the deployment - nothing else filters the
     * attributes that reach the ingestor.
     *
     * @var null|callable(array<string,mixed>):bool
     */
    public $eventFilter;

    /**
     * Headers added to every ingest request, for a deployment whose gateway also fronts the
     * ingestor. Separate from the SDK's apiHostRequestHeaders - this is a different host.
     *
     * @var array<string,string>
     */
    public array $ingestorRequestHeaders;

    /**
     * @param string                                  $ingestorHost
     * @param int                                     $batchSize
     * @param bool                                    $enableFeatureUsageEvents
     * @param bool                                    $enable
     * @param int                                     $dedupeCacheSize
     * @param array<string>|null                      $dedupeKeyAttributes
     * @param null|callable(array<string,mixed>):bool $eventFilter
     * @param array<array-key,mixed>                  $ingestorRequestHeaders
     * @throws \InvalidArgumentException When an ingestor header name or value is not usable
     */
    public function __construct(
        string $ingestorHost = self::DEFAULT_INGESTOR_HOST,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        bool $enableFeatureUsageEvents = true,
        bool $enable = true,
        int $dedupeCacheSize = self::DEFAULT_DEDUPE_CACHE_SIZE,
        ?array $dedupeKeyAttributes = null,
        ?callable $eventFilter = null,
        array $ingestorRequestHeaders = []
    ) {
        $this->ingestorHost             = rtrim($ingestorHost, "/");
        $this->batchSize                = max(1, $batchSize);
        $this->enableFeatureUsageEvents = $enableFeatureUsageEvents;
        $this->enable                   = $enable;
        $this->dedupeCacheSize          = max(0, $dedupeCacheSize);
        $this->dedupeKeyAttributes      = $dedupeKeyAttributes;
        $this->eventFilter              = $eventFilter;
        $this->ingestorRequestHeaders   = self::validateHeaders($ingestorRequestHeaders);
    }

    /**
     * The same rules the SDK applies to its own API headers, repeated here because this config
     * is independent of a Growthbook instance: a bad header has to fail where it is configured,
     * not inside the send where it would read as a dropped batch.
     *
     * @param array<array-key,mixed> $headers
     * @return array<string,string>
     */
    private static function validateHeaders(array $headers): array
    {
        $validated = [];
        $seen = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $name)) {
                throw new \InvalidArgumentException("Invalid ingestor header name: " . (is_string($name) ? "\"$name\"" : gettype($name)));
            }
            $lowercased = strtolower($name);
            if (in_array($lowercased, self::RESERVED_INGESTOR_HEADERS, true)) {
                throw new \InvalidArgumentException("Ingestor header \"$name\" is set by the plugin and cannot be overridden");
            }
            if (isset($seen[$lowercased])) {
                throw new \InvalidArgumentException("Ingestor header \"$name\" is a duplicate of \"{$seen[$lowercased]}\"");
            }
            $seen[$lowercased] = $name;

            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException("Value of ingestor header \"$name\" must be a string or a number");
            }
            $value = (string) $value;
            // The value is never echoed back in the message - it may be a secret.
            if (!preg_match('/^[\x21-\x7E\x80-\xFF]+(?:[ \t]+[\x21-\x7E\x80-\xFF]+)*\z/', $value)) {
                throw new \InvalidArgumentException("Value of ingestor header \"$name\" is not a valid header value");
            }
            $validated[$name] = $value;
        }

        return $validated;
    }
}
