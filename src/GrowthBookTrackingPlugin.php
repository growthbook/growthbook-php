<?php

namespace Growthbook;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Built-in plugin that batches experiment and feature evaluation events
 * and POSTs them to the GrowthBook ingest endpoint.
 *
 * Endpoint:  POST {ingestorHost}/track?client_key={clientKey}
 * Default host: https://us1.gb-ingest.com
 * Body: [...events]  (plain JSON array)
 *
 * Configure via GrowthBookTrackingPluginConfig.
 * Flush triggers:
 *   - batch reaches config->batchSize events
 *   - close() is called (e.g. on object destruction or end of request)
 */
class GrowthBookTrackingPlugin implements Plugin, EventLoggingPlugin
{
    private const PACKAGE_NAME = "growthbook/growthbook";

    private GrowthBookTrackingPluginConfig $config;
    private string $clientKey = "";
    private bool $initialized = false;
    private bool $closed = false;

    /** @var array<int, array<string, mixed>> */
    private array $queue = [];

    /**
     * Signatures of events already sent, newest last. Insertion order is the recency order, so
     * the oldest entry is the first key.
     *
     * @var array<string, true>
     */
    private array $recentlySent = [];

    /** Signatures of the batch in flight, so a failed send can give them back. */
    /** @var array<int, string> */
    private array $inFlightKeys = [];

    private int $postCount = 0;

    private ?ClientInterface $httpClient;
    private ?RequestFactoryInterface $requestFactory;
    private ?LoggerInterface $logger;

    /** @var callable|null — overrides HTTP sending in tests */
    private $sendHandler;

    /**
     * @param GrowthBookTrackingPluginConfig|null $config         Plugin configuration; defaults used when null
     * @param ClientInterface|null                $httpClient     PSR-18 client; auto-discovered when null
     * @param RequestFactoryInterface|null        $requestFactory PSR-17 factory; auto-discovered when null
     * @param LoggerInterface|null                $logger         PSR-3 logger; errors are silently dropped when null
     */
    public function __construct(
        ?GrowthBookTrackingPluginConfig $config = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config         = $config ?? new GrowthBookTrackingPluginConfig();
        $this->httpClient     = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->logger         = $logger;
        $this->sendHandler    = null;

        // Flush remaining events when the PHP process shuts down (covers the
        // case where close() is never called explicitly).
        register_shutdown_function([$this, 'close']);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function initialize(string $clientKey): void
    {
        if (empty($clientKey)) {
            return;
        }
        $this->clientKey   = $clientKey;
        $this->initialized = true;
    }

    /**
     * @param InlineExperiment<mixed> $experiment
     * @param ExperimentResult<mixed> $result
     * @param array<string,mixed>     $attributes
     */
    public function onExperimentViewed(InlineExperiment $experiment, ExperimentResult $result, array $attributes): void
    {
        if (!$this->initialized || !$this->config->enable) {
            return;
        }
        $this->enqueue([
            'event_name' => 'Experiment Viewed',
            'properties' => [
                'experimentId' => $experiment->key,
                // The variation's key, not its index: analysis joins exposures on the key, so an
                // experiment with custom variation keys matched nothing when the index was sent.
                // Matches the JS, Go and Kotlin SDKs.
                'variationId'  => $result->key,
                // Without the bucketing unit an exposure cannot be attributed to the user it
                // was assigned to.
                'hashAttribute' => $result->hashAttribute,
                'hashValue'     => $result->hashValue,
            ],
            'attributes' => $this->mergedAttributes($attributes),
        ]);
    }

    /**
     * @param FeatureResult<mixed> $result
     * @param array<string,mixed>  $attributes
     */
    public function onFeatureEvaluated(string $featureKey, FeatureResult $result, array $attributes): void
    {
        if (!$this->initialized || !$this->config->enable || !$this->config->enableFeatureUsageEvents) {
            return;
        }
        $this->enqueue([
            'event_name' => 'Feature Evaluated',
            'properties' => [
                'feature' => $featureKey,
                'value'   => $result->value,
                'source'  => $result->source,
                // The reference SDKs record "$default" for a default value and an empty string
                // for a rule without an id, so the same evaluation reads the same everywhere.
                'ruleId'  => self::ruleIdFor($result),
                // Which variation produced the value, when an experiment decided it
                'variationId' => $result->experimentResult->key ?? "",
            ],
            'attributes' => $this->mergedAttributes($attributes),
        ]);
    }

    /**
     * Custom events sent with Growthbook::logEvent(). Never deduplicated: the application chose
     * to send each one, so a repeat is a second occurrence rather than noise.
     *
     * @param string              $eventName
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $attributes
     */
    public function onLogEvent(string $eventName, array $properties, array $attributes): void
    {
        if (!$this->initialized || !$this->config->enable) {
            return;
        }
        // Not deduplicated: the application chose to send each one, so a repeat is a second
        // occurrence rather than noise.
        $this->enqueue([
            'event_name' => $eventName,
            'properties' => $properties,
            'attributes' => $this->mergedAttributes($attributes),
        ], false);
    }

    /**
     * @param FeatureResult<mixed> $result
     * @return string
     */
    private static function ruleIdFor(FeatureResult $result): string
    {
        if ($result->source === "defaultValue") {
            return "\$default";
        }

        return $result->ruleId ?? "";
    }

    /**
     * @param array<string,mixed> $userAttributes
     * @return array<string,mixed>
     */
    private function mergedAttributes(array $userAttributes): array
    {
        return array_merge($userAttributes, [
            'sdk_language' => 'php',
            'sdk_version'  => self::sdkVersion(),
        ]);
    }

    private static function sdkVersion(): string
    {
        try {
            return \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /** Synchronously flush all buffered events. Safe to call multiple times. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->flush();
    }

    /**
     * @param array<string, mixed> $event
     * @param bool                 $deduplicate
     */
    private function enqueue(array $event, bool $deduplicate = true): void
    {
        $filter = $this->config->eventFilter;
        if ($filter !== null && !$filter($event)) {
            return;
        }

        if ($deduplicate && $this->config->dedupeCacheSize > 0) {
            $key = $this->dedupeKey($event);
            if (isset($this->recentlySent[$key])) {
                return;
            }
            $this->remember($key);
            $this->inFlightKeys[] = $key;
        }

        $this->queue[] = $event;
        if (count($this->queue) >= $this->config->batchSize) {
            $this->flush();
        }
    }

    /**
     * Signature of an event for deduplication: the event itself plus the attributes that
     * identify who it belongs to. Dropping the attributes entirely would let one user's
     * evaluation suppress another's, which is the opposite of the problem being solved.
     *
     * @param array<string, mixed> $event
     * @return string
     */
    private function dedupeKey(array $event): string
    {
        $attributes = is_array($event['attributes'] ?? null) ? $event['attributes'] : [];
        if ($this->config->dedupeKeyAttributes !== null) {
            $selected = [];
            foreach ($this->config->dedupeKeyAttributes as $name) {
                $selected[$name] = $attributes[$name] ?? null;
            }
            $attributes = $selected;
        }
        ksort($attributes);

        return md5((string) json_encode([$event['event_name'] ?? '', $event['properties'] ?? [], $attributes]));
    }

    /**
     * @param string $key
     * @return void
     */
    private function remember(string $key): void
    {
        $this->recentlySent[$key] = true;
        while (count($this->recentlySent) > $this->config->dedupeCacheSize) {
            $oldest = array_key_first($this->recentlySent);
            if ($oldest === null) {
                break;
            }
            unset($this->recentlySent[$oldest]);
        }
    }

    private function flush(): void
    {
        if (empty($this->queue)) {
            return;
        }
        $events      = $this->queue;
        $keys        = $this->inFlightKeys;
        $this->queue = [];
        $this->inFlightKeys = [];

        if (!$this->post($events)) {
            // A lost batch must not keep its events suppressed: the next identical evaluation
            // would be dropped as a duplicate of something nobody ever received.
            foreach ($keys as $key) {
                unset($this->recentlySent[$key]);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function post(array $events): bool
    {
        $payload = json_encode($events);

        if ($payload === false) {
            return false;
        }

        $url = $this->config->ingestorHost . '/track?client_key=' . rawurlencode($this->clientKey);

        $this->postCount++;
        if ($this->postCount === 2 && $this->logger) {
            // The documented ingest limits are 100 events per request and one request per
            // second, and a PHP request sends its batches back to back. Worth saying once:
            // the knobs for it are enableFeatureUsageEvents and eventFilter.
            $this->logger->log(LogLevel::WARNING, "GrowthBookTrackingPlugin: more than one ingest request in a single PHP request, which exceeds the documented rate limit", [
                "batchSize" => $this->config->batchSize,
            ]);
        }

        // Test hook — bypasses real HTTP. Returning false stands in for a dropped batch, which
        // is the only way to exercise the "give the dedupe keys back" path without a client.
        if ($this->sendHandler !== null) {
            return ($this->sendHandler)($url, $payload) !== false;
        }

        try {
            $client  = $this->resolveHttpClient();
            $factory = $this->resolveRequestFactory();
            if ($client === null || $factory === null) {
                return false;
            }

            $request = $factory->createRequest('POST', $url);
            foreach ($this->config->ingestorRequestHeaders as $name => $value) {
                $request = $request->withHeader($name, $value);
                \assert($request instanceof \Psr\Http\Message\RequestInterface);
            }
            $request = $request->withHeader('Content-Type', 'application/json');
            $request = $request->withHeader('User-Agent', 'growthbook-php-sdk/' . self::sdkVersion());
            $request = $request->withBody($this->createStream($factory, $payload));
            \assert($request instanceof \Psr\Http\Message\RequestInterface);

            $response = $client->sendRequest($request);

            // A 4xx/5xx is a lost batch like any other, and its events have to stay reportable
            return $response->getStatusCode() < 400;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->log(LogLevel::ERROR, "GrowthBookTrackingPlugin: failed to send events", [
                    "error" => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    private function resolveHttpClient(): ?ClientInterface
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }
        try {
            return \Http\Discovery\Psr18ClientDiscovery::find();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveRequestFactory(): ?RequestFactoryInterface
    {
        if ($this->requestFactory !== null) {
            return $this->requestFactory;
        }
        try {
            return \Http\Discovery\Psr17FactoryDiscovery::findRequestFactory();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function createStream(RequestFactoryInterface $factory, string $body): \Psr\Http\Message\StreamInterface
    {
        $streamFactory = \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory();
        return $streamFactory->createStream($body);
    }

    /**
     * For testing only — injects a callable that receives the request URL and
     * raw JSON body instead of making a real HTTP request.
     *
     * @param callable(string $url, string $body):(void|bool) $handler Returning false marks the
     *                                                                   batch as not delivered
     */
    public function setSendHandler(callable $handler): void
    {
        $this->sendHandler = $handler;
    }
}
