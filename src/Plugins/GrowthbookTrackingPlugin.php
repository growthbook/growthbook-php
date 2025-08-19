<?php

namespace Growthbook\Plugins;

use Growthbook\EventLoggerInterface;
use Growthbook\Growthbook;
use Psr\Log\LogLevel;

/**
 * @typedef EventPayload
 * @type array{
 *   event_name: string,
 *   properties_json: array<string,mixed>,
 *   sdk_language: string,
 *   sdk_version: string,
 *   url: string,
 *   context_json: array<string,mixed>,
 *   user_id: string|null,
 *   device_id: string|null,
 *   page_id: string|null,
 *   session_id: string|null,
 *   page_title?: string,
 *   utm_source?: string,
 *   utm_medium?: string,
 *   utm_campaign?: string,
 *   utm_term?: string,
 *   utm_content?: string
 * }
 */

class GrowthbookTrackingPlugin extends GrowthbookPlugin implements EventLoggerInterface
{
    // Configurable options
    /** @var string */
    private $ingestorHost;
    /** @var bool */
    private $enabled;
    /** @var int */
    private $dedupeCacheSize;
    /** @var array<string> */
    private $dedupeKeyAttributes;
    /** @var (callable(array<GrowthbookEventPayload>): bool) | null */
    private $eventFilter;

    // Internal state
    /** @var array<string> */
    private $dedupeCache = [];
    /** @var array<GrowthbookEventPayload> */
    private $eventQueue = [];

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->ingestorHost = $options['ingestorHost'] ?? "https://us1.gb-ingest.com";
        $this->enabled = $options['enabled'] ?? true;
        $this->dedupeCacheSize = $options['dedupeCacheSize'] ?? 1000;
        $this->dedupeKeyAttributes = $options['dedupeKeyAttributes'] ?? [];

        if (isset($options['eventFilter']) && is_callable($options['eventFilter'])) {
            $this->eventFilter = $options['eventFilter'];
        }
    }

    public function setup(): void
    {
        if (empty($this->growthbook->getClientKey())) {
            throw new \Exception('Client key is required to use gbTrackingPlugin');
        }

        if (!$this->growthbook->httpClient || !$this->growthbook->requestFactory || !$this->growthbook->streamFactory) {
            throw new \Exception('HTTP client, request and stream factories are required to use gbTrackingPlugin');
        }

        $this->growthbook->withEventLogger($this);

        // Send all events in a single request on shutdown
        register_shutdown_function(function () {
            $this->flush();
        });
    }

    private function flush(): void
    {
        if (empty($this->eventQueue)) {
            return;
        }

        $events = $this->eventQueue;
        $this->eventQueue = [];

        try {
            $this->track($events);
        } catch (\Exception $e) {
            $this->growthbook->log(
                LogLevel::ERROR,
                '[GrowthbookTrackingPlugin] Error flushing events',
                ['error' => $e->getMessage(), 'events' => $events]
            );
        }
    }

    /**
     * @param array<GrowthbookEventPayload> $events
     */
    private function track(array $events): void
    {
        // To help the linter
        $httpClient = $this->growthbook->httpClient;
        $requestFactory = $this->growthbook->requestFactory;
        $streamFactory = $this->growthbook->streamFactory;

        // Should never happen because it is checked in initialize
        if (!$httpClient || !$requestFactory || !$streamFactory) {
            throw new \Exception('[GrowthbookTrackingPlugin] Invalid state found. HTTP client, request or stream factories are not defined but should be');
        }

        if (empty($events)) {
            $this->growthbook->log(
                LogLevel::DEBUG,
                '[GrowthbookTrackingPlugin] Not sending track request as events is empty',
                ['events' => $events]
            );
            return;
        }

        // Convert EventPayload objects to arrays for JSON encoding
        $eventsArray = array_map(function (GrowthbookEventPayload $event) {
            return $event->toArray();
        }, $events);

        $jsonData = json_encode($eventsArray);
        if ($jsonData === false) {
            $this->growthbook->log(
                LogLevel::ERROR,
                '[GrowthbookTrackingPlugin] Failed to encode events as JSON',
                ['events' => $events]
            );
            return;
        }

        $body = $streamFactory->createStream($jsonData);

        $req = $requestFactory->createRequest('POST', $this->getIngestorFullUrl())
            ->withBody($body)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Content-Length', (string) strlen($jsonData));

        $this->growthbook->log(
            LogLevel::DEBUG,
            '[GrowthbookTrackingPlugin] Sending events to ingestor',
            ['events' => $events]
        );

        try {
            $res = $httpClient->sendRequest($req);
            if ($res->getStatusCode() < 200 || $res->getStatusCode() >= 300) {
                $this->growthbook->log(
                    LogLevel::ERROR,
                    '[GrowthbookTrackingPlugin] Ingestor returned non-200 status code',
                    [
                        'statusCode' => $res->getStatusCode(),
                        'responseBody' => $res->getBody(),
                        'events' => $events
                    ]
                );
                return;
            }
        } catch (\Exception $e) {
            $this->growthbook->log(
                LogLevel::ERROR,
                '[GrowthbookTrackingPlugin] Failed to send events to ingestor',
                ['error' => $e->getMessage(), 'events' => $events]
            );
            return;
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return GrowthbookEventPayload
     */
    private function getEventPayload(array $data): GrowthbookEventPayload
    {
        return new GrowthbookEventPayload([
            'event_name' => $data['eventName'],
            'properties_json' => $data['properties'],
            'sdk_language' => 'php',
            'sdk_version' => $this->growthbook->getVersion(),
            'url' => $data['url'],
            'context_json' => $data['attributes'],
            'user_id' => $data['attributes']['user_id'] ?? null,
            'device_id' => $data['attributes']['device_id'] ?? null,
            'page_id' => $data['attributes']['page_id'] ?? null,
            'session_id' => $data['attributes']['session_id'] ?? null,
            'page_title' => $data['attributes']['page_title'] ?? null,
            'utm_source' => $data['attributes']['utm_source'] ?? null,
            'utm_medium' => $data['attributes']['utm_medium'] ?? null,
            'utm_campaign' => $data['attributes']['utm_campaign'] ?? null,
            'utm_term' => $data['attributes']['utm_term'] ?? null,
            'utm_content' => $data['attributes']['utm_content'] ?? null,
        ]);
    }

    /**
     * @param callable(array<string,mixed>): bool $filter
     * @return $this
     */
    public function setEventFilter(callable $filter): GrowthbookTrackingPlugin
    {
        $this->eventFilter = $filter;
        return $this;
    }

    /**
     * @param string $host
     * @return $this
     */
    public function setIngestorHost(string $host): GrowthbookTrackingPlugin
    {
        $this->ingestorHost = $host;
        return $this;
    }

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setEnabled(bool $enabled): GrowthbookTrackingPlugin
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * @param int $size
     * @return $this
     */
    public function setDedupeCacheSize(int $size): GrowthbookTrackingPlugin
    {
        $this->dedupeCacheSize = $size;
        return $this;
    }

    /**
     * @param array<string> $attributes
     * @return $this
     */
    public function setDedupeKeyAttributes(array $attributes): GrowthbookTrackingPlugin
    {
        $this->dedupeKeyAttributes = $attributes;
        return $this;
    }

    /**
     * @param string $eventName
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $userContext
     */
    public function logEvent(string $eventName, array $properties, array $userContext): void
    {
        $data = [
            'eventName' => $eventName,
            'properties' => $properties,
            'attributes' => $userContext['attributes'] ?? [],
            'url' => $userContext['url'] ?? '',
        ];

        // Skip logging if the event is being filtered
        if ($this->eventFilter && !call_user_func($this->eventFilter, $data)) {
            return;
        }

        // Skip deduplication entirely when no dedupe attributes are configured
        if (!empty($this->dedupeKeyAttributes)) {
            // Build the key for de-duping
            $dedupeKeyData = [];
            foreach ($this->dedupeKeyAttributes as $key) {
                $dedupeKeyData['attr:' . $key] = $data['attributes'][$key];
            }

            $dedupeKey = json_encode($dedupeKeyData);
            if ($dedupeKey === false) {
                $this->growthbook->log(
                    LogLevel::ERROR,
                    '[GrowthbookTrackingPlugin] Failed to encode dedupe key. Not processing event',
                    [
                        'dedupeKeyData' => $dedupeKeyData,
                        'eventData' => $data,
                    ]
                );
                return;
            }

            // Duplicate event fired recently, move to end of LRU cache and skip
            if (in_array($dedupeKey, $this->dedupeCache)) {
                // Remove and re-add to move to end
                $this->dedupeCache = array_values(array_diff($this->dedupeCache, [$dedupeKey]));
                $this->dedupeCache[] = $dedupeKey;
                return;
            }

            // Register the event as recently fired
            $this->dedupeCache[] = $dedupeKey;

            // If the cache is too big, remove the oldest item
            if (count($this->dedupeCache) > $this->dedupeCacheSize) {
                array_shift($this->dedupeCache);
            }
        }

        $payload = $this->getEventPayload($data);

        $this->growthbook->log(
            LogLevel::DEBUG,
            '[GrowthbookTrackingPlugin] Adding event to queue',
            $payload->toArray()
        );

        if (!$this->enabled) {
            return;
        }

        $this->eventQueue[] = $payload;
    }

    /**
     * @return string
     */
    private function getIngestorFullUrl(): string
    {
        $params = ['clientKey' => $this->growthbook->getClientKey()];
        return $this->ingestorHost . '/track?' . http_build_query($params);
    }
}

/**
 * Represents an event payload structure for GrowthBook tracking
 */
class GrowthbookEventPayload
{
    /** @var string */
    public $event_name;

    /** @var array<string,mixed> */
    public $properties_json;

    /** @var string */
    public $sdk_language;

    /** @var string */
    public $sdk_version;

    /** @var string */
    public $url;

    /** @var array<string,mixed> */
    public $context_json;

    /** @var string|null */
    public $user_id;

    /** @var string|null */
    public $device_id;

    /** @var string|null */
    public $page_id;

    /** @var string|null */
    public $session_id;

    /** @var string|null */
    public $page_title;

    /** @var string|null */
    public $utm_source;

    /** @var string|null */
    public $utm_medium;

    /** @var string|null */
    public $utm_campaign;

    /** @var string|null */
    public $utm_term;

    /** @var string|null */
    public $utm_content;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        $this->event_name = $data['event_name'];
        $this->properties_json = $data['properties_json'];
        $this->sdk_language = $data['sdk_language'];
        $this->sdk_version = $data['sdk_version'];
        $this->url = $data['url'];
        $this->context_json = $data['context_json'];
        $this->user_id = $data['user_id'] ?? null;
        $this->device_id = $data['device_id'] ?? null;
        $this->page_id = $data['page_id'] ?? null;
        $this->session_id = $data['session_id'] ?? null;
        $this->page_title = $data['page_title'] ?? null;
        $this->utm_source = $data['utm_source'] ?? null;
        $this->utm_medium = $data['utm_medium'] ?? null;
        $this->utm_campaign = $data['utm_campaign'] ?? null;
        $this->utm_term = $data['utm_term'] ?? null;
        $this->utm_content = $data['utm_content'] ?? null;
    }

    /**
     * Convert to array for JSON serialization
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'event_name' => $this->event_name,
            'properties_json' => $this->properties_json,
            'sdk_language' => $this->sdk_language,
            'sdk_version' => $this->sdk_version,
            'url' => $this->url,
            'context_json' => $this->context_json,
            'user_id' => $this->user_id,
            'device_id' => $this->device_id,
            'page_id' => $this->page_id,
            'session_id' => $this->session_id,
            'page_title' => $this->page_title,
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'utm_term' => $this->utm_term,
            'utm_content' => $this->utm_content,
        ];
    }
}
