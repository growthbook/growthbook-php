<?php

namespace Growthbook;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Built-in plugin that batches experiment and feature evaluation events
 * and POSTs them to the GrowthBook ingest endpoint.
 *
 * Endpoint:  POST {ingestorHost}/track
 * Default host: https://us1.gb-ingest.com
 * Body: { "client_key": "...", "events": [...] }
 *
 * Flush triggers:
 *   - batch reaches $batchSize events
 *   - close() is called (e.g. on object destruction or end of request)
 */
class GrowthBookTrackingPlugin implements Plugin
{
    public const DEFAULT_INGESTOR_HOST = "https://us1.gb-ingest.com";
    public const DEFAULT_BATCH_SIZE    = 100;

    private const SDK_VERSION = "2.0.0";

    private string $ingestorHost;
    private int $batchSize;
    private string $clientKey = "";
    private bool $initialized = false;
    private bool $closed = false;

    /** @var array<int, array<string, mixed>> */
    private array $queue = [];

    private ?ClientInterface $httpClient;
    private ?RequestFactoryInterface $requestFactory;

    /** @var callable|null — overrides HTTP sending in tests */
    private $sendHandler;

    /**
     * @param string                        $ingestorHost
     * @param int                           $batchSize
     * @param ClientInterface|null          $httpClient      PSR-18 client; auto-discovered when null
     * @param RequestFactoryInterface|null  $requestFactory  PSR-17 factory; auto-discovered when null
     */
    public function __construct(
        string $ingestorHost = self::DEFAULT_INGESTOR_HOST,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null
    ) {
        $this->ingestorHost   = rtrim($ingestorHost, "/");
        $this->batchSize      = max(1, $batchSize);
        $this->httpClient     = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->sendHandler    = null;

        // Flush remaining events when the PHP process shuts down (covers the
        // case where close() is never called explicitly).
        register_shutdown_function([$this, 'close']);
    }

    public function __destruct()
    {
        $this->close();
    }

    // -------------------------------------------------------------------------
    // Plugin interface
    // -------------------------------------------------------------------------

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
     */
    public function onExperimentViewed(InlineExperiment $experiment, ExperimentResult $result): void
    {
        if (!$this->initialized) {
            return;
        }
        $this->enqueue([
            'event'         => 'experiment_viewed',
            'experimentKey' => $experiment->key,
            'variationId'   => $result->variationId,
            'hashAttribute' => $result->hashAttribute,
            'hashValue'     => $result->hashValue,
        ]);
    }

    /**
     * @param FeatureResult<mixed> $result
     */
    public function onFeatureEvaluated(string $featureKey, FeatureResult $result): void
    {
        if (!$this->initialized) {
            return;
        }
        $this->enqueue([
            'event'      => 'feature_evaluated',
            'featureKey' => $featureKey,
            'value'      => $result->value,
            'source'     => $result->source,
            'ruleId'     => $result->ruleId ?? null,
        ]);
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

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $event
     */
    private function enqueue(array $event): void
    {
        $this->queue[] = $event;
        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if (empty($this->queue)) {
            return;
        }
        $events      = $this->queue;
        $this->queue = [];
        $this->post($events);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function post(array $events): void
    {
        $payload = json_encode([
            'client_key' => $this->clientKey,
            'events'     => $events,
        ]);

        if ($payload === false) {
            return;
        }

        // Test hook — bypasses real HTTP
        if ($this->sendHandler !== null) {
            ($this->sendHandler)($payload);
            return;
        }

        try {
            $client  = $this->resolveHttpClient();
            $factory = $this->resolveRequestFactory();
            if ($client === null || $factory === null) {
                return;
            }

            $request = $factory
                ->createRequest('POST', $this->ingestorHost . '/track')
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('User-Agent', 'growthbook-php-sdk/' . self::SDK_VERSION)
                ->withBody($this->createStream($factory, $payload));

            $client->sendRequest($request);
        } catch (\Throwable $e) {
            // never propagate network errors
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
     * For testing only — injects a callable that receives the raw JSON payload
     * instead of making a real HTTP request.
     *
     * @param callable(string):void $handler
     */
    public function setSendHandler(callable $handler): void
    {
        $this->sendHandler = $handler;
    }
}
