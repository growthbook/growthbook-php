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
class GrowthBookTrackingPlugin implements Plugin
{
    private const PACKAGE_NAME = "growthbook/growthbook";

    private GrowthBookTrackingPluginConfig $config;
    private string $clientKey = "";
    private bool $initialized = false;
    private bool $closed = false;

    /** @var array<int, array<string, mixed>> */
    private array $queue = [];

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
        if (!$this->initialized) {
            return;
        }
        $this->enqueue([
            'event_name' => 'Experiment Viewed',
            'properties' => [
                'experimentId' => $experiment->key,
                'variationId'  => $result->variationId,
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
        if (!$this->initialized) {
            return;
        }
        $this->enqueue([
            'event_name' => 'Feature Evaluated',
            'properties' => [
                'feature' => $featureKey,
                'value'   => $result->value,
                'source'  => $result->source,
                'ruleId'  => $result->ruleId ?? null,
            ],
            'attributes' => $this->mergedAttributes($attributes),
        ]);
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
     */
    private function enqueue(array $event): void
    {
        $this->queue[] = $event;
        if (count($this->queue) >= $this->config->batchSize) {
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
        $payload = json_encode($events);

        if ($payload === false) {
            return;
        }

        $url = $this->config->ingestorHost . '/track?client_key=' . rawurlencode($this->clientKey);

        // Test hook — bypasses real HTTP
        if ($this->sendHandler !== null) {
            ($this->sendHandler)($url, $payload);
            return;
        }

        try {
            $client  = $this->resolveHttpClient();
            $factory = $this->resolveRequestFactory();
            if ($client === null || $factory === null) {
                return;
            }

            $request = $factory->createRequest('POST', $url);
            $request = $request->withHeader('Content-Type', 'application/json');
            $request = $request->withHeader('User-Agent', 'growthbook-php-sdk/' . self::sdkVersion());
            $request = $request->withBody($this->createStream($factory, $payload));
            \assert($request instanceof \Psr\Http\Message\RequestInterface);

            $client->sendRequest($request);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->log(LogLevel::ERROR, "GrowthBookTrackingPlugin: failed to send events", [
                    "error" => $e->getMessage(),
                ]);
            }
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
     * @param callable(string $url, string $body):void $handler
     */
    public function setSendHandler(callable $handler): void
    {
        $this->sendHandler = $handler;
    }
}
