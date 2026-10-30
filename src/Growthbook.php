<?php

namespace Growthbook;

use Exception;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class Growthbook implements LoggerAwareInterface
{
    private const DEFAULT_API_HOST = "https://cdn.growthbook.io";
    private const DEFAULT_API_TIMEOUT = 2;

    /** @var bool */
    public $enabled = true;
    /** @var null|\Psr\Log\LoggerInterface */
    public $logger = null;
    /** @var string */
    private $url = "";
    /** @var array<string,mixed> */
    private $attributes = [];
    /** @var array<string,array<string,mixed>> */
    private $savedGroups = [];
    /** @var Feature<mixed>[] */
    private $features = [];
    /** @var array<string, FeatureResult<mixed>> */
    private $forcedFeatures = [];
    /** @var array<string,int> */
    private $forcedVariations = [];
    /** @var bool */
    public $qaMode = false;
    /** @var callable|null */
    private $trackingCallback = null;
    /** @var callable|null */
    private $featureUsageCallback = null;
    /** @var array<string,string> */
    private $trackedFeatures = [];

    /**
     * @var null|\Psr\SimpleCache\CacheInterface
     */
    private $cache = null;
    /**
     * @var integer
     */
    private $cacheTTL = 60;

    /**
     * @var null|\Psr\Http\Client\ClientInterface
     */
    private $httpClient = null;

    /**
     * @var null|\Psr\Http\Message\RequestFactoryInterface;
     */
    public $requestFactory = null;

    /** @var string */
    private $apiHost = "";
    /** @var string */
    private $clientKey = "";
    /** @var string */
    private $decryptionKey = "";

    /** @var array<string,ViewedExperiment> */
    private $tracks = [];

    /** @var StickyBucketService|null */
    private $stickyBucketService = null;

    /** @var LruETagCache */
    private LruETagCache $etagCache;

    /** @var array<string>|null */
    private $stickyBucketIdentifierAttributes = null;
    /** @var array<string, array> */
    private $stickyBucketAssignmentDocs = [];
    /** @var bool */
    private $usingDerivedStickyBucketAttributes;
    /** @var null|array<string, string> */
    private $stickyBucketAttributes = null;
    /** @var int */
    private $apiTimeout = self::DEFAULT_API_TIMEOUT;

    /** @var int */
    private $apiConnectTimeout = self::DEFAULT_API_TIMEOUT;

    /** @var PluginRegistry */
    private $pluginRegistry;

    /** @var bool */
    private $remoteEval = false;

    /** @var array<string>|null */
    private $cacheKeyAttributes = null;

    /**
     * The remote saved groups the user is in, resolved by the app. null means
     * unresolved, which is not the same as no groups.
     *
     * @var string[]|null
     */
    private $remoteGroupIds = null;

    /** @var array<int,array{id:string,attributeKey:string,valuesFrom?:string}> The payload's remote saved groups */
    private $remoteSavedGroups = [];

    /** @var array<string,mixed> The attributes conditions see: the user's, with the remote group ids */
    private $conditionAttributes = [];

    /** @var bool Warned once per process, since a request usually gets its own instance */
    private static $warnedMissingRemoteGroupIds = false;

    /**
     * @param array<string, mixed> $options
     * @return static
     * @phpstan-return static
     */
    public static function create(array $options = []): static
    {
        // @phpstan-ignore-next-line
        return new static($options);
    }

    /**
     * @param array{enabled?:bool,logger?:\Psr\Log\LoggerInterface,url?:string,attributes?:array<string,mixed>,features?:array<string,mixed>,savedGroups?:array<string,array<string,mixed>>,forcedVariations?:array<string,int>,qaMode?:bool,trackingCallback?:callable,cache?:\Psr\SimpleCache\CacheInterface,httpClient?:\Psr\Http\Client\ClientInterface,requestFactory?:\Psr\Http\Message\RequestFactoryInterface,decryptionKey?:string,forcedFeatures?:array<string, FeatureResult<mixed>>,plugins?:Plugin[],clientKey?:string,remoteEval?:bool,cacheKeyAttributes?:array<string>,remoteGroupIds?:string[]|null} $options
     */
    public function __construct(array $options = [])
    {
        // Warn if any unknown options are passed
        $knownOptions = [
            "enabled",
            "logger",
            "url",
            "attributes",
            "features",
            "forcedVariations",
            "forcedFeatures",
            "qaMode",
            "trackingCallback",
            "cache",
            "httpClient",
            "requestFactory",
            "decryptionKey",
            "stickyBucketService",
            "stickyBucketIdentifierAttributes",
            "savedGroups",
            "encryptedSavedGroups",
            "apiTimeout",
            "apiConnectTimeout",
            "plugins",
            "clientKey",
            "etagCacheSize",
            "remoteEval",
            "cacheKeyAttributes",
            "remoteGroupIds"
        ];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error('Unknown Config options: ' . implode(", ", $unknownOptions), E_USER_NOTICE);
        }

        $this->enabled = $options["enabled"] ?? true;
        $this->logger = $options["logger"] ?? null;
        $this->pluginRegistry = new PluginRegistry([], $this->logger);
        $this->url = $options["url"] ?? $_SERVER['REQUEST_URI'] ?? "";
        $this->forcedVariations = $options["forcedVariations"] ?? [];
        $this->qaMode = $options["qaMode"] ?? false;
        $this->trackingCallback = $options["trackingCallback"] ?? null;

        $this->decryptionKey = $options["decryptionKey"] ?? "";

        $this->cache = $options["cache"] ?? null;
        $this->apiTimeout = $options["apiTimeout"] ?? self::DEFAULT_API_TIMEOUT;
        $this->apiConnectTimeout = $options["apiConnectTimeout"] ?? self::DEFAULT_API_TIMEOUT;
        try {
            $this->httpClient = $options["httpClient"] ?? $this->createDefaultHttpClient();
            $this->requestFactory = $options["requestFactory"] ?? Psr17FactoryDiscovery::findRequestFactory();
        } catch (\Throwable $e) {
            // Ignore errors from discovery
        }

        $this->stickyBucketService = $options["stickyBucketService"] ?? null;
        $this->stickyBucketIdentifierAttributes = $options["stickyBucketIdentifierAttributes"] ?? null;
        $this->usingDerivedStickyBucketAttributes = !isset($this->stickyBucketIdentifierAttributes);

        $this->etagCache = new LruETagCache($options["etagCacheSize"] ?? 100);

        if (array_key_exists("forcedFeatures", $options)) {
            $this->setForcedFeatures($options['forcedFeatures']);
        }
        if (array_key_exists("features", $options)) {
            $this->setFeatures(($options["features"]));
        }
        if (array_key_exists("attributes", $options)) {
            $this->setAttributes(($options["attributes"]));
        }
        if (array_key_exists("savedGroups", $options)) {
            $this->setSavedGroups(($options["savedGroups"]));
        }
        if (array_key_exists("remoteGroupIds", $options)) {
            $this->setRemoteGroupIds($options["remoteGroupIds"]);
        }
        if (array_key_exists("clientKey", $options)) {
            $this->setClientKey($options["clientKey"]);
        }
        if (array_key_exists("plugins", $options)) {
            foreach ($options["plugins"] as $plugin) {
                $this->addPlugin($plugin);
            }
        }
        if (array_key_exists("cacheKeyAttributes", $options)) {
            $this->setCacheKeyAttributes($options["cacheKeyAttributes"]);
        }
        if (array_key_exists("remoteEval", $options)) {
            $this->setRemoteEval($options["remoteEval"]);
        }
    }

    /**
     * The fluent `withX()` methods hand out copies of this instance (per-request or per-user
     * handles), and a PHP clone is shallow: the copy shares this instance's plugin registry.
     * Register the copy as an owner so the plugins are only closed once every handle is gone.
     */
    public function __clone()
    {
        $this->pluginRegistry->retain();
    }

    public function __destruct()
    {
        $this->pluginRegistry->release();
    }

    /**
     * @param array<string,mixed> $attributes
     * @return static
     */
    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;
        $this->conditionAttributes = RemoteSavedGroups::withIds($attributes, $this->remoteGroupIds);
        $this->refreshStickyBuckets();
        $this->refreshForRemoteEval();

        return $this;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return static
     */
    public function withAttributes(array $attributes): static
    {
        $self = clone $this;
        $self->setAttributes($attributes);

        return $self;
    }

    /**
     * @param array<string,mixed>|\stdClass $savedGroups An object is accepted, as `reducePayload` returns
     * @return static
     */
    public function setSavedGroups(array|\stdClass $savedGroups): static
    {
        $this->savedGroups = (array) $savedGroups;
        $this->remoteSavedGroups = RemoteSavedGroups::fromSavedGroups($this->savedGroups);

        return $this;
    }

    /**
     * @param array<string,mixed>|\stdClass $savedGroups An object is accepted, as `reducePayload` returns
     * @return static
     */
    public function withSavedGroups(array|\stdClass $savedGroups): static
    {
        $self = clone $this;
        $self->setSavedGroups($savedGroups);

        return $self;
    }

    /**
     * Sets the remote saved groups the user is in. A remote saved group keeps its
     * members outside the payload, so the app resolves the user's groups and passes
     * their ids here, or uses addRemoteSavedGroups(). Conditions read them as the
     * `__gb_remoteGroupIds` attribute, over any value the attributes set for it, and
     * remote evaluation sends them too.
     *
     * Pass null while the groups are unresolved: rules that use remote groups then
     * fail, including "not in group". Don't pass [] for that: it means "in no
     * group", so "not in group" rules match.
     *
     * @param string[]|null $remoteGroupIds
     * @return static
     */
    public function setRemoteGroupIds(?array $remoteGroupIds): static
    {
        $this->remoteGroupIds = $remoteGroupIds === null ? null : array_values(array_map('strval', $remoteGroupIds));
        $this->conditionAttributes = RemoteSavedGroups::withIds($this->attributes, $this->remoteGroupIds);
        $this->refreshForRemoteEval();

        return $this;
    }

    /**
     * @param string[]|null $remoteGroupIds
     * @return static
     */
    public function withRemoteGroupIds(?array $remoteGroupIds): static
    {
        $self = clone $this;
        $self->setRemoteGroupIds($remoteGroupIds);

        return $self;
    }

    /**
     * @return string[]|null
     */
    public function getRemoteGroupIds(): ?array
    {
        return $this->remoteGroupIds;
    }

    /**
     * Looks up the user's remote saved groups with $resolver and sets them as the
     * remote group ids. Leaves them as they were when the payload has no remote
     * groups or the resolver throws.
     *
     * Requires a saved group references v2 payload for the group metadata. With
     * inline/v1 payloads, resolve membership in the app and call setRemoteGroupIds()
     * instead.
     *
     * @param callable(array<string,mixed>,array<int,array{id:string,attributeKey:string,valuesFrom?:string}>):array<string> $resolver
     *        Called with the attributes the payload's remote groups use (keyed by
     *        attributeKey) and the groups ('id', 'attributeKey' and, for a derived
     *        group, 'valuesFrom'). Returns the ids of the groups the user is in.
     *        See RedisResolver.
     * @return static
     */
    public function addRemoteSavedGroups(callable $resolver): static
    {
        // No remote entries doesn't mean no remote rules: payloads for SDKs without
        // remote support check __gb_remoteGroupIds directly. Leaving it unset makes
        // those rules fail closed; [] would let every "not in group" rule match.
        $groups = $this->getRemoteSavedGroups();
        if (!$groups) {
            return $this;
        }
        $savedGroups = $this->savedGroups;
        try {
            $ids = RemoteSavedGroups::resolve($this->attributes, $groups, $resolver);
            // New saved groups set during the lookup can add groups the result
            // doesn't cover, and a missing group would pass "not in group" rules.
            // Look up once more for the new payload.
            if ($this->savedGroups !== $savedGroups) {
                $groups = $this->getRemoteSavedGroups();
                if (!$groups) {
                    return $this;
                }
                $ids = RemoteSavedGroups::resolve($this->attributes, $groups, $resolver);
            }
        } catch (\Throwable $e) {
            // The class only: messages can hold attribute values, such as a Redis key
            $this->log(LogLevel::WARNING, "Could not resolve remote saved groups", ["error" => get_class($e)]);
            return $this;
        }

        return $this->setRemoteGroupIds($ids);
    }

    /**
     * @param callable(array<string,mixed>,array<int,array{id:string,attributeKey:string,valuesFrom?:string}>):array<string> $resolver
     * @return static
     */
    public function withRemoteSavedGroups(callable $resolver): static
    {
        $self = clone $this;
        $self->addRemoteSavedGroups($resolver);

        return $self;
    }

    /**
     * The payload's remote saved groups, as a resolver gets them
     *
     * @return array<int,array{id:string,attributeKey:string,valuesFrom?:string}>
     */
    private function getRemoteSavedGroups(): array
    {
        return $this->remoteSavedGroups;
    }

    /**
     * Logs once when the payload has remote saved groups but the user has no remote
     * group ids, since every rule using them will then fail. Logs no attribute values.
     */
    private function warnIfRemoteGroupIdsMissing(): void
    {
        if (self::$warnedMissingRemoteGroupIds || !$this->logger) {
            return;
        }
        if (RemoteSavedGroups::isMissingIds($this->attributes, $this->remoteGroupIds, $this->remoteSavedGroups)) {
            self::$warnedMissingRemoteGroupIds = true;
            $this->log(
                LogLevel::WARNING,
                "The payload has remote saved groups, but the user has no remote group ids. Rules using them won't match. See addRemoteSavedGroups."
            );
        }
    }

    /**
     * @param callable|null $trackingCallback
     * @return static
     */
    public function setTrackingCallback($trackingCallback): static
    {
        $this->trackingCallback = $trackingCallback;

        return $this;
    }

    /**
     * @param callable|null $trackingCallback
     * @return static
     */
    public function withTrackingCallback($trackingCallback): static
    {
        $self = clone $this;
        $self->setTrackingCallback($trackingCallback);

        return $self;
    }

    /**
     * @param array<string,Feature<mixed>|mixed>|\stdClass $features An object is accepted, as `reducePayload` returns
     * @return static
     */
    public function setFeatures(array|\stdClass $features): static
    {
        $this->features = [];
        foreach ((array) $features as $key => $feature) {
            if ($feature instanceof Feature) {
                $this->features[$key] = $feature;
            } else {
                $this->features[$key] = new Feature($feature);
            }
        }
        $this->refreshStickyBuckets();

        return $this;
    }

    /**
     * @param array<string,Feature<mixed>|mixed>|\stdClass $features An object is accepted, as `reducePayload` returns
     * @return static
     */
    public function withFeatures(array|\stdClass $features): static
    {
        $self = clone $this;
        $self->setFeatures($features);

        return $self;
    }

    /**
     * @param array<string,int> $forcedVariations
     * @return static
     */
    public function setForcedVariations(array $forcedVariations): static
    {
        $this->forcedVariations = $forcedVariations;
        $this->refreshForRemoteEval();
        return $this;
    }

    /**
     * @param array<string,int> $forcedVariations
     * @return static
     */
    public function withForcedVariations(array $forcedVariations): static
    {
        $self = clone $this;
        $self->setForcedVariations($forcedVariations);

        return $self;
    }

    /**
     * @param array<string, FeatureResult<mixed>> $forcedFeatures
     * @return static
     */
    public function setForcedFeatures(array $forcedFeatures): static
    {
        $this->forcedFeatures = $forcedFeatures;
        return $this;
    }

    /**
     * @param array<string, FeatureResult<mixed>> $forcedFeatures
     * @return static
     */
    public function withForcedFeatures(array $forcedFeatures): static
    {
        $self = clone $this;
        $self->setForcedFeatures($forcedFeatures);

        return $self;
    }

    /**
     * @param string $url
     * @return static
     */
    public function setUrl(string $url): static
    {
        $this->url = $url;
        $this->refreshForRemoteEval();
        return $this;
    }

    /**
     * @param string $url
     * @return static
     */
    public function withUrl(string $url): static
    {
        $self = clone $this;
        $self->setUrl($url);

        return $self;
    }

    /**
     * @param LoggerInterface|null $logger
     */
    public function setLogger(?LoggerInterface $logger = null): void
    {
        $this->logger = $logger;
        $this->pluginRegistry->setLogger($logger);
    }

    /**
     * @param LoggerInterface|null $logger
     * @return static
     */
    public function withLogger(?LoggerInterface $logger = null): static
    {
        $self = clone $this;
        $self->setLogger($logger);

        return $self;
    }

    /**
     * @param \Psr\Http\Client\ClientInterface $client
     * @param \Psr\Http\Message\RequestFactoryInterface $requestFactory
     * @return static
     */
    public function setHttpClient(\Psr\Http\Client\ClientInterface $client, \Psr\Http\Message\RequestFactoryInterface $requestFactory): static
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;
        return $this;
    }

    /**
     * Set the HTTP client and request factory to use for API requests.
     *
     * @param \Psr\Http\Client\ClientInterface $client
     * @param \Psr\Http\Message\RequestFactoryInterface $requestFactory
     * @return static
     */
    public function withHttpClient(\Psr\Http\Client\ClientInterface $client, \Psr\Http\Message\RequestFactoryInterface $requestFactory): static
    {
        $self = clone $this;
        $self->setHttpClient($client, $requestFactory);

        return $self;
    }

    /**
     * Set API request timeout in seconds
     * 
     * @param int $seconds Timeout in seconds (default: 2)
     * @return static
     */
    public function setApiTimeout(int $seconds): static
    {
        $this->apiTimeout = max(1, $seconds); // min 1 sec
        return $this;
    }

    /**
     * Set API request timeout in seconds
     *
     * @param int $seconds Timeout in seconds (default: 2)
     * @return static
     */
    public function withApiTimeout(int $seconds): static
    {
        $self = clone $this;
        $self->setApiTimeout($seconds);

        return $self;
    }

    /**
     * Set API connection timeout in seconds
     * 
     * @param int $seconds Connection timeout in seconds (default: 2)
     * @return static
     */
    public function setApiConnectTimeout(int $seconds): static
    {
        $this->apiConnectTimeout = max(1, $seconds);
        return $this;
    }

    /**
     * Set API connection timeout in seconds
     *
     * @param int $seconds Connection timeout in seconds (default: 2)
     * @return static
     */
    public function withApiConnectTimeout(int $seconds): static
    {
        $self = clone $this;
        $self->setApiConnectTimeout($seconds);

        return $self;
    }

    /**
     * @param \Psr\SimpleCache\CacheInterface $cache
     * @param int|null                       $ttl
     * @return static
     */
    public function setCache(\Psr\SimpleCache\CacheInterface $cache, ?int $ttl = null): static
    {
        $this->cache = $cache;
        if ($ttl !== null) {
            $this->cacheTTL = $ttl;
        }

        return $this;
    }

    /**
     * @param \Psr\SimpleCache\CacheInterface $cache
     * @param int|null                       $ttl
     * @return static
     */
    public function withCache(\Psr\SimpleCache\CacheInterface $cache, ?int $ttl = null): static
    {
        $self = clone $this;
        $self->setCache($cache, $ttl);

        return $self;
    }

    public function addPlugin(Plugin $plugin): static
    {
        // Pass the client key if we already have one: plugins used to receive initialize() only
        // from initialize(), so one added afterwards stayed uninitialized and dropped its events.
        $this->pluginRegistry->add($plugin, $this->clientKey);
        return $this;
    }

    /**
     * Set the SDK Connection client key without fetching features.
     *
     * `initialize()` sets it as a side effect, but an app that loads features itself and passes
     * them in with `setFeatures()` never calls it - and plugins that need the key, such as the
     * built-in tracking plugin, then have nothing to send events with. Setting it here hands it
     * to every plugin registered so far, and to any added later.
     *
     * @param string $clientKey
     * @return static
     */
    public function setClientKey(string $clientKey): static
    {
        $this->assertClientKeyIsCompatible($clientKey);
        $this->detachFromSharedRegistryWhenChangingConnection($clientKey);
        $this->clientKey = $clientKey;
        if ($clientKey !== "") {
            $this->initializePlugins();
        }

        return $this;
    }

    /**
     * @param string $clientKey
     * @return static
     */
    public function withClientKey(string $clientKey): static
    {
        $self = clone $this;
        $self->setClientKey($clientKey);

        return $self;
    }

    public function getClientKey(): string
    {
        return $this->clientKey;
    }

    /**
     * A copy that takes its own client key before any plugin exists gets its own registry.
     *
     * Copies share the registry, so a plugin added to one of them later would be keyed with
     * that handle's connection and would receive this one's events as well - reported under
     * the wrong client key. Once plugins are registered the change is refused instead (see
     * [assertClientKeyIsCompatible]); this only applies while there is nothing to move.
     *
     * @param string $clientKey
     * @return void
     */
    private function detachFromSharedRegistryWhenChangingConnection(string $clientKey): void
    {
        if ($clientKey === $this->clientKey) {
            return;
        }
        if ($this->pluginRegistry->hasPlugins() || !$this->pluginRegistry->isShared()) {
            return;
        }

        $this->pluginRegistry->release();
        $this->pluginRegistry = new PluginRegistry([], $this->logger);
    }

    /**
     * Plugins are keyed once and shared with every copy of this instance, so once they report to
     * one connection this instance - and its copies - cannot move to another: the features would
     * come from one SDK connection and the events would land in the other. Use a separate
     * Growthbook instance, with its own plugins, for a second connection.
     *
     * @param string $clientKey
     * @return void
     * @throws Exception
     */
    private function assertClientKeyIsCompatible(string $clientKey): void
    {
        if ($clientKey === "" || !$this->pluginRegistry->hasPlugins()) {
            return;
        }
        if (!$this->pluginRegistry->isKeyedWithOther($clientKey)) {
            return;
        }

        throw new Exception(
            "Plugins are already sending to another SDK connection, so this instance cannot switch to \"$clientKey\". " .
            "Create a separate Growthbook instance, with its own plugins, for the other connection."
        );
    }

    /**
     * Enable or disable remote evaluation mode. When enabled, feature evaluation
     * is delegated to a self-hosted GrowthBook proxy/edge via POST /api/eval/{clientKey}.
     *
     * @param bool $remoteEval
     * @return static
     */
    public function setRemoteEval(bool $remoteEval): static
    {
        $this->remoteEval = $remoteEval;
        return $this;
    }

    /**
     * @param bool $remoteEval
     * @return static
     */
    public function withRemoteEval(bool $remoteEval): static
    {
        $self = clone $this;
        $self->setRemoteEval($remoteEval);

        return $self;
    }

    /**
     * @return bool
     */
    public function isRemoteEval(): bool
    {
        return $this->remoteEval;
    }

    /**
     * Restrict which attributes participate in the remote eval cache key.
     * Only meaningful when remote evaluation is enabled. Pass null to use all attributes.
     *
     * @param array<string>|null $cacheKeyAttributes
     * @return static
     */
    public function setCacheKeyAttributes(?array $cacheKeyAttributes): static
    {
        $this->cacheKeyAttributes = $cacheKeyAttributes;
        return $this;
    }

    /**
     * @param array<string>|null $cacheKeyAttributes
     * @return static
     */
    public function withCacheKeyAttributes(?array $cacheKeyAttributes): static
    {
        $self = clone $this;
        $self->setCacheKeyAttributes($cacheKeyAttributes);

        return $self;
    }

    /**
     * @return array<string>|null
     */
    public function getCacheKeyAttributes(): ?array
    {
        return $this->cacheKeyAttributes;
    }

    /**
     * @param array<string>|null $stickyBucketIdentifierAttributes
     */
    public function setStickyBucketing(StickyBucketService $stickyBucketService, ?array $stickyBucketIdentifierAttributes): static
    {
        $this->stickyBucketService = $stickyBucketService;
        $this->stickyBucketIdentifierAttributes = $stickyBucketIdentifierAttributes;
        $this->usingDerivedStickyBucketAttributes = !isset($this->stickyBucketIdentifierAttributes);
        $this->refreshStickyBuckets();

        return $this;
    }

    /**
     * @param StickyBucketService $stickyBucketService
     * @param array<string>|null  $stickyBucketIdentifierAttributes
     * @return static
     */
    public function withStickyBucketing(StickyBucketService $stickyBucketService, ?array $stickyBucketIdentifierAttributes): static
    {
        $self = clone $this;
        $self->setStickyBucketing($stickyBucketService, $stickyBucketIdentifierAttributes);

        return $self;
    }

    /**
     * @return array<string,mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return array<string,Feature<mixed>>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * @return array<string,int>
     */
    public function getForcedVariations(): array
    {
        return $this->forcedVariations;
    }

    /**
     * @return array<string, FeatureResult<mixed>>
     */
    public function getForcedFeatures(): array
    {
        return $this->forcedFeatures;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return callable|null
     */
    public function getTrackingCallback(): ?callable
    {
        return $this->trackingCallback;
    }

    /**
     * @param callable|null $featureUsageCallback
     * @return static
     */
    public function setFeatureUsageCallback($featureUsageCallback): static
    {
        $this->featureUsageCallback = $featureUsageCallback;

        return $this;
    }

    /**
     * @param callable|null $featureUsageCallback
     * @return static
     */
    public function withFeatureUsageCallback($featureUsageCallback): static
    {
        $self = clone $this;
        $self->setFeatureUsageCallback($featureUsageCallback);

        return $self;
    }

    /**
     * @return callable|null
     */
    public function getFeatureUsageCallback(): ?callable
    {
        return $this->featureUsageCallback;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getSavedGroups(): array
    {
        return $this->savedGroups;
    }

    /**
     * @return ViewedExperiment[]
     */
    public function getViewedExperiments(): array
    {
        return array_values($this->tracks);
    }

    public function isOn(string $key): bool
    {
        return $this->getFeature($key)->on;
    }

    public function isOff(string $key): bool
    {
        return $this->getFeature($key)->off;
    }

    /**
     * @template T
     * @param string $key
     * @param T      $default
     * @return T
     */
    public function getValue(string $key, $default)
    {
        $res = $this->getFeature($key);
        return $res->value ?? $default;
    }

    /**
     * A condition that throws, such as a malformed one ({"$not": null}), fails
     * rather than breaking evaluation
     *
     * @param array<string,mixed> $obj
     * @param mixed $condition
     * @return bool
     */
    private function evalConditionSafely(array $obj, $condition): bool
    {
        try {
            return Condition::evalCondition($obj, $condition, $this->savedGroups);
        } catch (\Throwable $e) {
            $this->log(LogLevel::WARNING, "Condition evaluation failed", [
                "condition" => $condition,
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @param array<array<string, mixed>> $parentConditions
     * @param array<string> $stack
     * @return string
     */
    private function evalPrereqs(array $parentConditions, array $stack): string
    {
        // Track results by condition ID - a condition passes if any instance with same ID passes
        $resultByConditionId = [];

        foreach ($parentConditions as $parentCondition) {
            $conditionId = $parentCondition['id'] ?? null;
            $parentRes = $this->evaluateFeature($conditionId, $stack);

            if ($parentRes->source === "cyclicPrerequisite") {
                return "cyclic";
            }

            $conditionPassed = $this->evalConditionSafely(
                ['value' => $parentRes->value],
                $parentCondition['condition'] ?? []
            );

            if ($conditionPassed) {
                $resultByConditionId[$conditionId] = "pass";
            } elseif (!isset($resultByConditionId[$conditionId]) || $resultByConditionId[$conditionId] !== "pass") {
                // Only mark as gate/fail if pass is not already set
                $resultByConditionId[$conditionId] = $parentCondition['gate'] ?? false ? "gate" : "fail";
            }
        }

        if (in_array("gate", $resultByConditionId)) {
            return "gate";
        }
        if (in_array("fail", $resultByConditionId)) {
            return "fail";
        }
        return "pass";
    }

    /**
     * @template T
     * @param string $key
     * @param array<string>  $stack
     * @return FeatureResult<T>|FeatureResult<null>
     */
    public function getFeature(string $key, array $stack = []): FeatureResult
    {
        if (empty($stack)) {
            $this->warnIfRemoteGroupIdsMissing();
        }
        $result = $this->evaluateFeature($key, $stack);
        if (empty($stack)) {
            $this->pluginRegistry->onFeatureEvaluated($key, $result, $this->attributes);
        }
        return $result;
    }

    /**
     * @param string        $key
     * @param array<string> $stack
     * @return FeatureResult<mixed>
     */
    private function evaluateFeature(string $key, array $stack): FeatureResult
    {
        if (!array_key_exists($key, $this->features)) {
            $this->log(LogLevel::DEBUG, "Unknown feature - $key");
            $result = new FeatureResult(null, "unknownFeature");
            $this->fireFeatureUsageCallback($key, $result);
            return $result;
        }
        $this->log(LogLevel::DEBUG, "Evaluating feature - $key");
        $feature = $this->features[$key];

        if (in_array($key, $stack)) {
            $this->log(LogLevel::WARNING, "Cyclic prerequisite detected, stack", [
                "stack" => $stack,
            ]);
            $result = new FeatureResult(null, "cyclicPrerequisite");
            $this->fireFeatureUsageCallback($key, $result);
            return $result;
        }
        $stack[] = $key;

        // Check if the feature is forced
        if (array_key_exists($key, $this->forcedFeatures)) {
            $this->log(LogLevel::DEBUG, "Feature Forced - $key", [
                "feature" => $key,
                "result" => $this->forcedFeatures[$key],
            ]);

            return $this->forcedFeatures[$key];
        }

        if ($feature->rules) {
            foreach ($feature->rules as $rule) {
                if ($rule->parentConditions) {
                    $prereqRes = $this->evalPrereqs($rule->parentConditions, $stack);
                    if ($prereqRes === 'gate') {
                        $this->log(LogLevel::DEBUG, "Top-level prerequisite failed, return None, feature", [
                            "feature" => $key,
                        ]);
                        $result = new FeatureResult(null, "prerequisite");
                        $this->fireFeatureUsageCallback($key, $result);
                        return $result;
                    }
                    if ($prereqRes === 'cyclic') {
                        $result = new FeatureResult(null, "cyclicPrerequisite");
                        $this->fireFeatureUsageCallback($key, $result);
                        return $result;
                    }
                    if ($prereqRes === 'fail') {
                        $this->log(LogLevel::DEBUG, "Skip rule because of failing prerequisite, feature", [
                            "feature" => $key,
                        ]);
                        continue;
                    }
                }

                if ($rule->condition) {
                    if (!$this->evalConditionSafely($this->conditionAttributes, $rule->condition)) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of targeting condition", [
                            "feature" => $key,
                            "condition" => $rule->condition
                        ]);
                        continue;
                    }
                }
                if ($rule->filters) {
                    if (self::isFilteredOut($rule->filters)) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of filtering (e.g. namespace)", [
                            "feature" => $key,
                            "filters" => $rule->filters
                        ]);
                        continue;
                    }
                }

                $ruleFallback = ($this->stickyBucketService && !$rule->disableStickyBucketing) ? $rule->fallbackAttribute : null;
                if (isset($rule->force)) {
                    if (
                        !$this->isIncludedInRollout(
                            $rule->seed ?? $key,
                            $rule->hashAttribute,
                            $ruleFallback,
                            $rule->range,
                            $rule->coverage,
                            $rule->hashVersion
                        )
                    ) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of rollout percent", [
                            "feature" => $key
                        ]);
                        continue;
                    }
                    $this->log(LogLevel::DEBUG, "Force feature value from rule", [
                        "feature" => $key,
                        "value" => $rule->force
                    ]);
                    // Fire deferred experiment tracking events that were evaluated server-side (remote eval)
                    if ($rule->tracks) {
                        $this->fireRuleTracks($rule->tracks);
                    }
                    $result = new FeatureResult($rule->force, "force", null, null, $rule->id);
                    $this->fireFeatureUsageCallback($key, $result);
                    return $result;
                }
                $exp = $rule->toExperiment($key);
                if (!$exp) {
                    $this->log(LogLevel::DEBUG, "Skip rule because could not convert to an experiment", [
                        "feature" => $key,
                        "filters" => $rule->filters
                    ]);
                    continue;
                }
                $result = $this->runExperiment($exp, $key);
                if (!$result->inExperiment) {
                    $this->log(LogLevel::DEBUG, "Skip rule because user not included in experiment", [
                        "feature" => $key
                    ]);
                    continue;
                }
                if ($result->passthrough) {
                    $this->log(LogLevel::DEBUG, "User put into holdout experiment, continue to next rule", [
                        "feature" => $key
                    ]);
                    continue;
                }
                $this->log(LogLevel::DEBUG, "Use feature value from experiment", [
                    "feature" => $key,
                    "value" => $result->value
                ]);
                $featureResult = new FeatureResult($result->value, "experiment", $exp, $result, $rule->id);
                $this->fireFeatureUsageCallback($key, $featureResult);
                return $featureResult;
            }
        }
        $result = new FeatureResult($feature->defaultValue ?? null, "defaultValue");
        $this->fireFeatureUsageCallback($key, $result);
        return $result;
    }

    /**
     * @template T
     * @param InlineExperiment<T> $exp
     * @return ExperimentResult<T>
     */
    public function runInlineExperiment(InlineExperiment $exp): ExperimentResult
    {
        $this->warnIfRemoteGroupIdsMissing();
        return $this->runExperiment($exp, null);
    }

    /**
     * @template T
     * @param InlineExperiment<T> $exp
     * @param string|null         $featureId
     * @return ExperimentResult<T>
     */
    private function runExperiment(InlineExperiment $exp, ?string $featureId = null): ExperimentResult
    {
        $this->log(LogLevel::DEBUG, "Attempting to run experiment - " . $exp->key);
        // 1. Too few variations
        if (count($exp->variations) < 2) {
            $this->log(LogLevel::DEBUG, "Skip experiment because there aren't enough variations", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, "id", "", -1, false, $featureId);
        }

        // 2. Growthbook disabled
        if (!$this->enabled) {
            $this->log(LogLevel::DEBUG, "Skip experiment because the Growthbook instance is disabled", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, "id", "", -1, false, $featureId);
        }

        $fallback = ($this->stickyBucketService && !$exp->disableStickyBucketing) ? $exp->fallbackAttribute : null;
        list($hashAttribute, $hashValue) = $this->getHashValue($exp->hashAttribute, $fallback);
        // 3. Forced via querystring
        if ($this->url) {
            $qsOverride = static::getQueryStringOverride($exp->key, $this->url, count($exp->variations));
            if ($qsOverride !== null) {
                $this->log(LogLevel::DEBUG, "Force variation from querystring", [
                    "experiment" => $exp->key,
                    "variation" => $qsOverride
                ]);
                return new ExperimentResult($exp, $hashAttribute, $hashValue, $qsOverride, false, $featureId);
            }
        }

        // 4. Forced via forcedVariations
        if (array_key_exists($exp->key, $this->forcedVariations)) {
            $this->log(LogLevel::DEBUG, "Force variation from context", [
                "experiment" => $exp->key,
                "variation" => $this->forcedVariations[$exp->key]
            ]);
            return new ExperimentResult($exp, $hashAttribute, $hashValue, $this->forcedVariations[$exp->key], false, $featureId);
        }

        // 5. Experiment is not active
        if (!$exp->active) {
            $this->log(LogLevel::DEBUG, "Skip experiment because it is inactive", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
        }

        // 6. Hash value is empty
        if ($hashValue === "") {
            $this->log(LogLevel::DEBUG, "Skip experiment because of empty attribute - $hashAttribute", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashAttribute, "", -1, false, $featureId);
        }

        $assigned = -1;
        $foundStickyBucket = false;
        $stickyBucketVersionIsBlocked = false;

        if ($this->stickyBucketService && !$exp->disableStickyBucketing) {
            $stickyBucket = $this->getStickyBucketVariation(
                $exp->key,
                $exp->bucketVersion,
                $exp->minBucketVersion,
                $exp->meta,
                $exp->hashAttribute,
                $exp->fallbackAttribute
            );

            $foundStickyBucket = $stickyBucket['variation'] >= 0;
            $assigned = $stickyBucket['variation'];
            $stickyBucketVersionIsBlocked = $stickyBucket['versionIsBlocked'] ?? false;

            if ($foundStickyBucket) {
                $this->log(LogLevel::DEBUG, "Found sticky bucket for experiment, assigning sticky variation", [
                    "experiment" => $exp->key,
                    "variation" => $assigned
                ]);
            }
        }

        if (!$foundStickyBucket) {
            // 7. Filtered out / not in namespace
            if ($exp->filters) {
                if ($this->isFilteredOut($exp->filters)) {
                    $this->log(LogLevel::DEBUG, "Skip experiment because of filters (e.g. namespace)", [
                        "experiment" => $exp->key
                    ]);
                    return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
                }
            } elseif ($exp->namespace && !static::inNamespace($hashValue, $exp->namespace)) {
                $this->log(LogLevel::DEBUG, "Skip experiment because not in namespace", [
                    "experiment" => $exp->key
                ]);
                return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
            }

            // 8. Condition fails
            if ($exp->condition && !$this->evalConditionSafely($this->conditionAttributes, $exp->condition)) {
                $this->log(LogLevel::DEBUG, "Skip experiment because of targeting conditions", [
                    "experiment" => $exp->key
                ]);
                return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
            }

            //8.05 Exclude if parent conditions are not met
            if ($exp->parentConditions) {
                $prereqRes = $this->evalPrereqs($exp->parentConditions, []);
                if (in_array($prereqRes, ['gate', 'fail'])) {
                    $this->log(LogLevel::DEBUG, "Skip experiment because of failing prerequisite", [
                        "experiment" => $exp->key,
                    ]);
                    return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
                }
                if ($prereqRes === 'cyclic') {
                    $this->log(LogLevel::DEBUG, "Skip experiment because of cyclic prerequisite", [
                        "experiment" => $exp->key,
                    ]);
                    return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
                }
            }
        }

        // 9. Calculate bucket ranges
        $n = static::hash(
            $exp->seed ?? $exp->key,
            $hashValue,
            $exp->hashVersion ?? 1
        );
        if ($n === null) {
            $this->log(LogLevel::DEBUG, "Skip experiment because of invalid hash version", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
        }
        if (!$foundStickyBucket) {
            $ranges = $exp->ranges ?? static::getBucketRanges(count($exp->variations), $exp->coverage, $exp->weights ?? []);
            $assigned = static::chooseVariation($n, $ranges);
        }

        if ($stickyBucketVersionIsBlocked) {
            $this->log(LogLevel::DEBUG, "Skip experiment because sticky bucket version is blocked", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId, null, true);
        }

        // 10. Not assigned
        if ($assigned === -1) {
            $this->log(LogLevel::DEBUG, "Skip experiment because user is not included in a variation", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
        }


        // 11. Forced variation
        if ($exp->force !== null) {
            $this->log(LogLevel::DEBUG, "Force variation from the experiment config", [
                "experiment" => $exp->key,
                "variation" => $exp->force
            ]);
            return new ExperimentResult($exp, $hashAttribute, $hashValue, $exp->force, false, $featureId);
        }

        // 12. QA mode
        if ($this->qaMode) {
            $this->log(LogLevel::DEBUG, "Skip experiment because Growthbook instance in QA Mode", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
        }

        // 13. Build the result object
        $result = new ExperimentResult($exp, $hashAttribute, $hashValue, $assigned, true, $featureId, $n, $foundStickyBucket);

        //13.5 Persist sticky bucket
        if ($this->stickyBucketService && !$exp->disableStickyBucketing) {
            $assignments[$this->getStickyBucketExperimentKey($exp->key, $exp->bucketVersion ?? 0)] = $result->key;

            $data = $this->generateStickyBucketAssignmentDoc($hashAttribute, $hashValue, $assignments);

            $doc = $data['doc'] ?? null;
            if ($data['changed'] ?? false) {
                if (!$this->stickyBucketAssignmentDocs) {
                    $this->stickyBucketAssignmentDocs = [];
                }
                $this->stickyBucketAssignmentDocs[$data['key']] = $doc;
                $this->stickyBucketService->saveAssignments($doc);
            }
        }

        // 14. Fire tracking callback
        $dedupeKey = $result->hashAttribute . $result->hashValue . $exp->key . $result->variationId;
        if (isset($this->tracks[$dedupeKey])) {
            return $result;
        }
        $this->tracks[$dedupeKey] = new ViewedExperiment($exp, $result);
        if ($this->trackingCallback) {
            try {
                call_user_func($this->trackingCallback, $exp, $result);
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->log(LogLevel::ERROR, "Error calling the trackingCallback function", [
                        "experiment" => $exp->key,
                        "error" => $e
                    ]);
                } else {
                    throw $e;
                }
            }
        }
        $this->pluginRegistry->onExperimentViewed($exp, $result, $this->attributes);

        // 15. Return the result
        $this->log(LogLevel::DEBUG, "Assigned user a variation", [
            "experiment" => $exp->key,
            "variation" => $assigned
        ]);
        return $result;
    }

    /**
     * @param string $level
     * @param string $message
     * @param mixed  $context
     */
    public function log(string $level, string $message, $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * @param string $key
     * @param FeatureResult<mixed> $result
     */
    private function fireFeatureUsageCallback(string $key, FeatureResult $result): void
    {
        if (!$this->featureUsageCallback) {
            return;
        }

        $value = json_encode($result->value) ?: '';
        if (isset($this->trackedFeatures[$key]) && $this->trackedFeatures[$key] === $value) {
            return;
        }
        $this->trackedFeatures[$key] = $value;

        try {
            call_user_func($this->featureUsageCallback, $key, $result);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->log(LogLevel::ERROR, "Error calling the featureUsageCallback function", [
                    "feature" => $key,
                    "error" => $e
                ]);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Fire tracking callbacks for experiments that were evaluated server-side and
     * attached to a force rule via its "tracks" array (remote evaluation).
     *
     * @param array<int, array{experiment?:array<string,mixed>,result?:array<string,mixed>}> $tracks
     * @return void
     */
    private function fireRuleTracks(array $tracks): void
    {
        foreach ($tracks as $track) {
            $expData = $track['experiment'] ?? null;
            $resData = $track['result'] ?? null;

            if (!is_array($expData) || !is_array($resData) || !isset($expData['key']) || !isset($expData['variations'])) {
                $this->log(LogLevel::WARNING, "Skipping malformed track entry");
                continue;
            }

            /** @var InlineExperiment<mixed> $exp */
            $exp = new InlineExperiment($expData['key'], $expData['variations'], $expData);

            $result = new ExperimentResult(
                $exp,
                (string)($resData['hashAttribute'] ?? 'id'),
                (string)($resData['hashValue'] ?? ''),
                (int)($resData['variationId'] ?? -1),
                (bool)($resData['hashUsed'] ?? false),
                $resData['featureId'] ?? null,
                isset($resData['bucket']) ? (float)$resData['bucket'] : null,
                (bool)($resData['stickyBucketUsed'] ?? false)
            );

            // Deduplicate identical tracking calls (same logic as runExperiment)
            $dedupeKey = $result->hashAttribute . $result->hashValue . $exp->key . $result->variationId;
            if (isset($this->tracks[$dedupeKey])) {
                continue;
            }
            $this->tracks[$dedupeKey] = new ViewedExperiment($exp, $result);

            if ($this->trackingCallback) {
                try {
                    call_user_func($this->trackingCallback, $exp, $result);
                } catch (\Throwable $e) {
                    if ($this->logger) {
                        $this->log(LogLevel::ERROR, "Error calling the trackingCallback function", [
                            "experiment" => $exp->key,
                            "error" => $e
                        ]);
                    } else {
                        throw $e;
                    }
                }
            }
            $this->pluginRegistry->onExperimentViewed($exp, $result, $this->attributes);
        }
    }

    public static function hash(string $seed, string $value, int $version): ?float
    {
        // New hashing algorithm
        // FNV-1a over UTF-16 code units, like the JS SDK, so non-ASCII text buckets the same
        if ($version === 2) {
            $n = Utf16::fnv1a32((string) Utf16::fnv1a32($seed . $value));
            return ($n % 10000) / 10000;
        } // Original hashing algorithm (with a bias flaw)
        elseif ($version === 1) {
            $n = Utf16::fnv1a32($value . $seed);
            return ($n % 1000) / 1000;
        }

        return null;
    }

    /**
     * @param float                  $n
     * @param array{0:float,1:float} $range
     * @return bool
     */
    public static function inRange(float $n, array $range): bool
    {
        return $n >= $range[0] && $n < $range[1];
    }

    /**
     * @param string                      $seed
     * @param string|null                 $hashAttribute
     * @param string|null                 $fallbackAttribute
     * @param array{0:float,1:float}|null $range
     * @param float|null                  $coverage
     * @param int|null                    $hashVersion
     * @return bool
     */
    private function isIncludedInRollout(string $seed, ?string $hashAttribute = null, ?string $fallbackAttribute = null, ?array $range = null, ?float $coverage = null, ?int $hashVersion = null): bool
    {
        if ($coverage === null && $range === null) {
            return true;
        }

        if ($coverage === 0.0 && $range === null) {
            return false;
        }

        list(, $hashValue) = $this->getHashValue($hashAttribute, $fallbackAttribute);
        if ($hashValue === "") {
            return false;
        }

        $n = self::hash($seed, $hashValue, $hashVersion ?? 1);
        if ($n === null) {
            return false;
        }
        if ($range) {
            return self::inRange($n, $range);
        } elseif ($coverage !== null) {
            return $n < $coverage;
        }

        return true;
    }

    /**
     * The attribute to hash and its value as a string. The value is "" when
     * neither the attribute nor the fallback has a usable value. Callers pass a
     * fallback only when sticky bucketing applies.
     *
     * @param string|null $hashAttribute
     * @param string|null $fallbackAttribute
     * @return array{0:string,1:string}
     */
    private function getHashValue(?string $hashAttribute = null, ?string $fallbackAttribute = null): array
    {
        $attribute = $hashAttribute ?: "id";
        $val = static::toHashValue($this->attributes[$attribute] ?? null);

        if ($val === "" && $fallbackAttribute) {
            $val = static::toHashValue($this->attributes[$fallbackAttribute] ?? null);
            if ($val !== "") {
                $attribute = $fallbackAttribute;
            }
        }

        return [$attribute, $val];
    }

    /**
     * An attribute value as the string to hash, like JS's String(). Only a
     * non-empty string or a finite, non-zero number can be hashed; anything
     * else, including booleans, arrays and objects, gives "".
     *
     * @param mixed $value
     * @return string
     */
    private static function toHashValue($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 0 ? "" : (string) $value;
        }
        if (is_float($value)) {
            if ($value == 0 || !is_finite($value)) {
                return "";
            }
            // Integral values have no decimal part, like JS (1.0 is "1")
            if (floor($value) === $value && abs($value) < 1e21) {
                return sprintf('%.0f', $value);
            }
            // Shortest form that round-trips, like JS
            return (string) json_encode($value);
        }
        return "";
    }

    /**
     * @param array{seed:string,ranges:array{0:float,1:float}[],hashVersion?:int,attribute?:string}[] $filters
     * @return bool
     */
    private function isFilteredOut(array $filters): bool
    {
        foreach ($filters as $filter) {
            // Like JS, a user without a usable hash value is filtered out
            list(, $hashValue) = $this->getHashValue($filter["attribute"] ?? "id");
            if ($hashValue === "") {
                return true;
            }

            $n = self::hash($filter["seed"] ?? "", $hashValue, $filter["hashVersion"] ?? 2);
            if ($n === null) {
                return true;
            }

            $filtered = false;
            foreach ($filter["ranges"] as $range) {
                if (self::inRange($n, $range)) {
                    $filtered = true;
                    break;
                }
            }
            if (!$filtered) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string                          $userId
     * @param array{0:string,1:float,2:float} $namespace
     * @return bool
     */
    public static function inNamespace(string $userId, array $namespace): bool
    {
        // @phpstan-ignore-next-line
        if (count($namespace) < 3) {
            return false;
        }
        $n = static::hash("__" . $namespace[0], $userId, 1);
        if ($n === null) {
            return false;
        }
        return $n >= $namespace[1] && $n < $namespace[2];
    }

    /**
     * @param int $numVariations
     * @return float[]
     */
    public static function getEqualWeights(int $numVariations): array
    {
        $weights = [];
        for ($i = 0; $i < $numVariations; $i++) {
            $weights[] = 1 / $numVariations;
        }
        return $weights;
    }

    /**
     * Shrinks a payload for a user whose attributes are only partly known, e.g.
     * on a server bootstrapping a client SDK. The result evaluates the same as
     * the full payload for anyone who agrees with the known attributes.
     *
     * @param array<string,mixed> $payload A decoded, decrypted features API response
     * @param array{attributes?:array<string,mixed>,include?:array<string,mixed>,exclude?:array<string,mixed>,prerequisites?:string} $options
     *   attributes: the attributes known for the user.
     *   include: keep only the features and auto experiments that match every
     *   filter ({projects?:string[], tags?:string[], customFields?:array<string,mixed[]>}).
     *   exclude: remove the features and auto experiments that match any filter.
     *   prerequisites: "keep" (default) sends the prerequisites of what is kept
     *   even when the filters remove them, so the client evaluates the same as
     *   the full payload. "filter" applies the filters to them too.
     * @return array<string,mixed> The `features`, `savedGroups` and `contextualBandits`
     *   maps are `stdClass` when PHP would JSON-encode them as arrays (empty, or keyed
     *   0, 1, ...). `reducePayload`, `withFeatures` and `withSavedGroups` accept them.
     */
    public static function reducePayload(array $payload, array $options = []): array
    {
        $attributes = $options['attributes'] ?? [];
        $include = $options['include'] ?? [];
        $exclude = $options['exclude'] ?? [];
        $prerequisites = $options['prerequisites'] ?? 'keep';
        $savedGroups = static::fromObjectMap($payload['savedGroups'] ?? null) ?? [];
        $allFeatures = static::fromObjectMap($payload['features'] ?? null) ?? [];
        $allExperiments = is_array($payload['experiments'] ?? null) ? $payload['experiments'] : [];

        // Only an array counts, as with `$in`, and any one of its values is enough
        $has = function ($actual, $want): bool {
            return is_array($want) && Condition::isIn($actual, $want);
        };

        // A rule without projects applies wherever its feature is delivered
        $ruleInProjects = function ($meta) use ($include, $has): bool {
            return !isset($include['projects'])
                || !is_array($meta)
                || !isset($meta['projects'])
                || $has($meta['projects'], $include['projects']);
        };
        // A feature or experiment has to list one of the projects, or be in all
        // of them. One without projects is in none, like one without tags.
        $inProjects = function (array $meta) use ($include, $has): bool {
            return !isset($include['projects'])
                || !empty($meta['allProjects'])
                || $has($meta['projects'] ?? null, $include['projects']);
        };
        // Kept when it matches every `include` filter and no `exclude` filter.
        // Only listed projects are excluded, so one in all projects stays.
        $matches = function ($meta) use ($include, $exclude, $has, $inProjects): bool {
            $m = is_array($meta) ? $meta : [];
            $field = function (string $k) use ($m) {
                return is_array($m['customFields'] ?? null) ? ($m['customFields'][$k] ?? null) : null;
            };
            if (!$inProjects($m)) {
                return false;
            }
            if (isset($include['tags']) && !$has($m['tags'] ?? null, $include['tags'])) {
                return false;
            }
            foreach ($include['customFields'] ?? [] as $k => $want) {
                if (!$has($field((string) $k), $want)) {
                    return false;
                }
            }
            if ($has($m['projects'] ?? null, $exclude['projects'] ?? null) || $has($m['tags'] ?? null, $exclude['tags'] ?? null)) {
                return false;
            }
            foreach ($exclude['customFields'] ?? [] as $k => $want) {
                if ($has($field((string) $k), $want)) {
                    return false;
                }
            }
            return true;
        };

        // Reduces a rule or an auto experiment
        $prune = function (array $item, bool $isRule) use ($attributes, $savedGroups): array {
            if (!isset($item['condition']) || !is_array($item['condition'])) {
                return $item;
            }
            $condition = Condition::pruneCondition($attributes, $item['condition'], $savedGroups);
            if ($condition === true) {
                unset($item['condition']);
            } elseif ($condition === false) {
                // Kept, since sticky buckets and forced variations skip an
                // experiment's condition, and a rule's prerequisites are checked before it
                $item['condition'] = ['$not' => new \stdClass()];
                // A force rule that never passes never returns its value. An
                // auto experiment's forced variation still applies to a
                // sticky bucketed user.
                if ($isRule && array_key_exists('force', $item)) {
                    $item['force'] = null;
                }
            } else {
                $item['condition'] = $condition;
            }
            return $item;
        };

        // Adds a feature, then the prerequisites the client evaluates for it,
        // even when the filters drop them. With "filter", only the filters
        // decide, except for holdouts, which aren't app features and are always kept.
        $added = [];
        $parentIds = function (array $item) use ($prerequisites): array {
            $ids = [];
            foreach ($item['parentConditions'] ?? [] as $parent) {
                $id = (string) ($parent['id'] ?? '');
                if ($prerequisites !== 'filter' || str_starts_with($id, '$holdout:')) {
                    $ids[] = $id;
                }
            }
            return $ids;
        };
        $add = function (string $id) use (&$add, &$added, $parentIds, $allFeatures, $inProjects, $ruleInProjects, $prune): void {
            if (!array_key_exists($id, $allFeatures) || array_key_exists($id, $added)) {
                return;
            }
            $feature = $allFeatures[$id];
            if (!is_array($feature)) {
                return;
            }
            // Rules scoped to other projects aren't served. A prerequisite from
            // another project keeps its own, as on the server.
            $scoped = $inProjects(is_array($feature['metadata'] ?? null) ? $feature['metadata'] : []);
            $rules = null;
            if (is_array($feature['rules'] ?? null)) {
                $rules = [];
                foreach ($feature['rules'] as $rule) {
                    if (!$scoped || $ruleInProjects($rule['metadata'] ?? null)) {
                        $rules[] = $prune($rule, true);
                    }
                }
                $feature['rules'] = $rules;
            }
            // Stored before its parents are added, so a prerequisite cycle ends
            $added[$id] = $feature;
            foreach ($rules ?? [] as $rule) {
                foreach ($parentIds($rule) as $parentId) {
                    $add($parentId);
                }
            }
        };

        foreach ($allFeatures as $id => $feature) {
            if ($matches(is_array($feature) ? ($feature['metadata'] ?? null) : null)) {
                $add((string) $id);
            }
        }
        $experiments = [];
        foreach ($allExperiments as $experiment) {
            if (is_array($experiment) && $matches($experiment['metadata'] ?? null)) {
                $experiments[] = $prune($experiment, false);
            }
        }
        foreach ($experiments as $experiment) {
            foreach ($parentIds($experiment) as $parentId) {
                $add($parentId);
            }
        }
        $features = $added;

        // The contextual bandits that kept rules and experiments use. A context
        // whose condition never passes can't be the first that does, so it goes.
        $banditMap = static::fromObjectMap($payload['contextualBandits'] ?? null);
        $allBandits = $banditMap ?? [];
        $bandits = [];
        $items = $experiments;
        foreach ($features as $feature) {
            foreach ($feature['rules'] ?? [] as $rule) {
                $items[] = $rule;
            }
        }
        foreach ($items as $item) {
            $ref = $item['contextualBanditRef'] ?? null;
            if (!is_string($ref) || $ref === '' || isset($bandits[$ref]) || !array_key_exists($ref, $allBandits)) {
                continue;
            }
            $bandit = $allBandits[$ref];
            // Evaluation tolerates a definition that isn't an object, so it stays
            if (!is_array($bandit) || static::isList($bandit)) {
                $bandits[$ref] = $bandit;
                continue;
            }
            $contexts = [];
            foreach ($bandit['contexts'] ?? [] as $context) {
                $condition = Condition::pruneCondition(
                    $attributes,
                    is_array($context['condition'] ?? null) ? $context['condition'] : [],
                    $savedGroups
                );
                if ($condition === false) {
                    continue;
                }
                $context['condition'] = $condition === true ? new \stdClass() : $condition;
                $contexts[] = $context;
            }
            $bandit['contexts'] = $contexts;
            $bandits[$ref] = $bandit;
        }

        // Groups that keep their condition as written: those evaluated against
        // something other than the user (each item in $elemMatch, or the
        // parent's value in a prerequisite), and the groups those reference
        $asWritten = [];
        $seen = [];
        $mark = function ($x, bool $user) use (&$mark, &$asWritten, &$seen, $savedGroups): void {
            if (is_string($x)) {
                if (!array_key_exists($x, $savedGroups) || isset($asWritten[$x])) {
                    return;
                }
                if ($user && isset($seen[$x])) {
                    return;
                }
                if ($user) {
                    $seen[$x] = true;
                } else {
                    $asWritten[$x] = true;
                }
                if (static::canReferenceGroups($savedGroups[$x])) {
                    $mark($savedGroups[$x], $user);
                }
            } elseif (is_array($x) || is_object($x)) {
                foreach ((array) $x as $k => $v) {
                    $mark($v, $user && $k !== '$elemMatch' && $k !== 'parentConditions');
                }
            }
        };
        // Walking the payload is only needed when there are groups to find
        $hasGroups = $savedGroups !== [];
        if ($hasGroups) {
            $mark([$features, $experiments, $bandits], true);
        }

        // A group on a cycle can't be reduced on its own: its result depends on
        // which group the evaluation entered the cycle from. Each group's
        // referenced ids are collected once, then one pass finds every group
        // on a cycle (a strongly connected component of more than one group,
        // or a group that references itself).
        $onCycle = static::savedGroupsOnCycle($savedGroups);

        // Keep each saved group whose id appears as a string anywhere, with
        // condition groups reduced. This needs no list of the operators that
        // reference groups.
        $groups = [];
        $keep = function ($x) use (&$keep, &$groups, $savedGroups, $attributes, $asWritten, $onCycle): void {
            if (is_string($x)) {
                if (!array_key_exists($x, $savedGroups) || array_key_exists($x, $groups)) {
                    return;
                }
                $condition = isset($asWritten[$x]) || isset($onCycle[$x])
                    ? null
                    : Condition::pruneSavedGroup($attributes, ['id' => $x], $savedGroups);
                $groups[$x] = is_array($condition) && is_array($savedGroups[$x])
                    ? array_merge($savedGroups[$x], ['condition' => $condition])
                    : $savedGroups[$x];
                if (static::canReferenceGroups($savedGroups[$x])) {
                    $keep($groups[$x]);
                }
            } elseif (is_array($x)) {
                foreach ($x as $value) {
                    $keep($value);
                }
            }
        };
        if ($hasGroups) {
            $keep([$features, $experiments, $bandits]);
        }

        $payload['features'] = static::toObjectMap($features);
        $payload['experiments'] = $experiments;
        $payload['savedGroups'] = static::toObjectMap($groups);
        // Only when there were any, as with the payload's other optional keys
        if ($banditMap !== null) {
            $payload['contextualBandits'] = static::toObjectMap($bandits);
        }
        return $payload;
    }

    /**
     * The ids of the saved groups that are on a reference cycle, found with
     * Tarjan's strongly connected components algorithm.
     *
     * @param array<string,mixed> $savedGroups
     * @return array<string,true>
     */
    private static function savedGroupsOnCycle(array $savedGroups): array
    {
        // Each group's referenced group ids, collected once
        $refs = [];
        foreach ($savedGroups as $id => $group) {
            $found = [];
            $collect = function ($x) use (&$collect, &$found, $savedGroups): void {
                if (is_string($x)) {
                    if (array_key_exists($x, $savedGroups)) {
                        $found[$x] = true;
                    }
                } elseif (is_array($x) || is_object($x)) {
                    foreach ((array) $x as $v) {
                        $collect($v);
                    }
                }
            };
            if (static::canReferenceGroups($group)) {
                $collect($group);
            }
            $refs[(string) $id] = array_map('strval', array_keys($found));
        }

        $index = 0;
        $indexes = [];
        $lowlinks = [];
        $stack = [];
        $onStack = [];
        $onCycle = [];
        $connect = function (string $v) use (&$connect, &$index, &$indexes, &$lowlinks, &$stack, &$onStack, &$onCycle, $refs): void {
            $indexes[$v] = $index;
            $lowlinks[$v] = $index;
            $index++;
            $stack[] = $v;
            $onStack[$v] = true;
            foreach ($refs[$v] as $w) {
                if (!isset($indexes[$w])) {
                    $connect($w);
                    $lowlinks[$v] = min($lowlinks[$v], $lowlinks[$w]);
                } elseif (isset($onStack[$w])) {
                    $lowlinks[$v] = min($lowlinks[$v], $indexes[$w]);
                }
            }
            if ($lowlinks[$v] !== $indexes[$v]) {
                return;
            }
            $component = [];
            do {
                $w = array_pop($stack);
                unset($onStack[$w]);
                $component[] = $w;
            } while ($w !== $v);
            if (count($component) > 1 || in_array($v, $refs[$v], true)) {
                foreach ($component as $w) {
                    $onCycle[$w] = true;
                }
            }
        };
        foreach (array_keys($refs) as $v) {
            if (!isset($indexes[$v])) {
                $connect($v);
            }
        }
        return $onCycle;
    }

    /**
     * Whether a saved group can reference other groups. A list's values, typed
     * or a legacy bare array, are attribute values, never group ids.
     *
     * @param mixed $group
     */
    private static function canReferenceGroups($group): bool
    {
        if (is_object($group)) {
            $group = (array) $group;
        }
        return is_array($group) && !static::isList($group) && ($group['type'] ?? null) !== 'list';
    }

    /**
     * Whether an array is a JSON list (sequential keys from 0). An empty array
     * counts as an object, since `{}` and `[]` decode the same.
     *
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        return $value !== [] && array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Keeps a map keyed by id an object when the result is JSON-encoded. PHP
     * encodes an empty array, or one whose keys are 0, 1, ..., as a JSON array.
     *
     * @param array<mixed> $map
     * @return array<mixed>|\stdClass
     */
    private static function toObjectMap(array $map): array|\stdClass
    {
        return $map === [] || static::isList($map) ? (object) $map : $map;
    }

    /**
     * Reads a map keyed by id as an array, whether it is an array or an object
     * from `toObjectMap`, so a reduced payload can be reduced again.
     *
     * @param mixed $map
     * @return array<mixed>|null Null when the map is neither
     */
    private static function fromObjectMap($map): ?array
    {
        return is_array($map) || $map instanceof \stdClass ? (array) $map : null;
    }

    /**
     * @param int            $numVariations
     * @param float          $coverage
     * @param null|(float[]) $weights
     * @return array{0:float,1:float}[]
     */
    public static function getBucketRanges(int $numVariations, float $coverage, ?array $weights = null): array
    {
        $coverage = max(0, min(1, $coverage));

        if (!$weights || count($weights) !== $numVariations) {
            $weights = static::getEqualWeights($numVariations);
        }
        $sum = array_sum($weights);
        if ($sum < 0.99 || $sum > 1.01) {
            $weights = static::getEqualWeights($numVariations);
        }

        $cumulative = 0;
        $ranges = [];
        foreach ($weights as $weight) {
            $start = $cumulative;
            $cumulative += $weight;
            $ranges[] = [$start, $start + $coverage * $weight];
        }
        return $ranges;
    }

    /**
     * @param float                    $n
     * @param array{0:float,1:float}[] $ranges
     * @return int
     */
    public static function chooseVariation(float $n, array $ranges): int
    {
        foreach ($ranges as $i => $range) {
            if (self::inRange($n, $range)) {
                return (int) $i;
            }
        }
        return -1;
    }

    /**
     * @param string $id
     * @param string $url
     * @param int    $numVariations
     * @return int|null
     */
    public static function getQueryStringOverride(string $id, string $url, int $numVariations): ?int
    {
        // Extract the querystring from the url
        /** @var string|false */
        $query = parse_url($url, PHP_URL_QUERY);
        if (!$query) {
            return null;
        }

        // Parse the query string and check if $id is there
        parse_str($query, $params);
        if (!isset($params[$id]) || !is_numeric($params[$id])) {
            return null;
        }

        // Make sure it's a valid variation integer
        $variation = (int) $params[$id];
        if ($variation < 0 || $variation >= $numVariations) {
            return null;
        }

        return $variation;
    }

    /**
     * @param string $encryptedString
     * @return string
     */
    public function decrypt(string $encryptedString): string
    {
        if (!$this->decryptionKey) {
            throw new \Error("Must specify a decryption key in order to use encrypted feature flags");
        }

        $parts = explode(".", $encryptedString, 2);
        $iv = base64_decode($parts[0]);
        $cipherText = $parts[1];

        $password = base64_decode($this->decryptionKey);

        $decrypted = openssl_decrypt($cipherText, "aes-128-cbc", $password, 0, $iv);
        if (!$decrypted) {
            throw new \Error("Failed to decrypt");
        }

        return $decrypted;
    }

    /**
     * @param string $clientKey
     * @param string $apiHost
     * @param string $decryptionKey
     * @return void
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function initialize(string $clientKey, string $apiHost = "", string $decryptionKey = ""): void
    {
        // Checked before anything is assigned: loading another connection's features while the
        // shared plugins keep reporting to the first one is worse than refusing outright
        $this->assertClientKeyIsCompatible($clientKey);
        $this->detachFromSharedRegistryWhenChangingConnection($clientKey);
        $this->clientKey = $clientKey;
        $this->apiHost = $apiHost;
        $this->decryptionKey = $decryptionKey;

        if (!$this->clientKey) {
            throw new Exception("Must specify a clientKey before initializing.");
        }

        // Remote evaluation delegates feature evaluation to a self-hosted proxy/edge
        if ($this->remoteEval) {
            $this->fetchRemoteEval();
            $this->initializePlugins();
            return;
        }

        $url = rtrim(empty($this->apiHost) ? self::DEFAULT_API_HOST : $this->apiHost, "/") . "/api/features/" . $this->clientKey;
        // Update when the cache format changes to prevent issues when updating the library version
        $cacheKey = md5($url . 'v2');

        // First try fetching from cache
        $cachedData = null;
        if ($this->cache) {
            $cachedResponse = $this->cache->get($cacheKey);
            if ($cachedResponse) {
                $decoded = json_decode($cachedResponse, true);
                if (is_array($decoded)) {
                    $isFresh = isset($decoded['expiresAt']) && $decoded['expiresAt'] > time();
                    if ($isFresh) {
                        if (array_key_exists("features", $decoded) && is_array($decoded['features'])) {
                            $this->log(LogLevel::INFO, "Load features from cache", ["url" => $url, "numFeatures" => count($decoded['features'])]);
                            $this->setFeatures($decoded['features']);
                        }
                        if (array_key_exists("savedGroups", $decoded) && is_array($decoded['savedGroups'])) {
                            $this->log(LogLevel::INFO, "Load saved groups from cache", ["url" => $url, "numGroups" => count($decoded['savedGroups'])]);
                            $this->setSavedGroups($decoded['savedGroups']);
                        }
                        $this->initializePlugins();
                        return;
                    }
                    $cachedData = $decoded;
                }
            }
        }

        if (!$this->httpClient || !$this->requestFactory) {
            if ($cachedData !== null) {
                $this->log(LogLevel::WARNING, "HTTP client not set, using stale cache as fallback", ["url" => $url]);
                if (array_key_exists("features", $cachedData) && is_array($cachedData['features'])) {
                    $this->setFeatures($cachedData['features']);
                }
                if (array_key_exists("savedGroups", $cachedData) && is_array($cachedData['savedGroups'])) {
                    $this->setSavedGroups($cachedData['savedGroups']);
                }
            } else {
                $this->log(LogLevel::WARNING, "HTTP client or request factory not set, unable to load features from API");
            }
            $this->initializePlugins();
            return;
        }

        try {
            // Fetch from API, with conditional GET if we have a cached ETag
            $req = $this->requestFactory->createRequest('GET', $url);
            $req = $req->withHeader('User-Agent', 'growthbook-php/' . $this->sdkVersion());
            \assert($req instanceof \Psr\Http\Message\RequestInterface);

            $cachedETag = $this->etagCache->get($url);
            if ($cachedETag !== null && $cachedData !== null) {
                $req = $req->withHeader('If-None-Match', $cachedETag);
                \assert($req instanceof \Psr\Http\Message\RequestInterface);
            }

            $res = $this->httpClient->sendRequest($req);
            $statusCode = $res->getStatusCode();

            // Handle 304 Not Modified - refresh cache TTL and reuse stale data
            if ($statusCode === 304 && $cachedData !== null) {
                $this->log(LogLevel::INFO, "304 Not Modified, refreshing cache TTL", ["url" => $url]);
                if ($this->cache && array_key_exists("features", $cachedData)) {
                    $cachedData['expiresAt'] = time() + $this->cacheTTL;
                    $this->cache->set($cacheKey, json_encode($cachedData), $this->cacheTTL * 10);
                }
                if (array_key_exists("features", $cachedData) && is_array($cachedData['features'])) {
                    $this->setFeatures($cachedData['features']);
                }
                if (array_key_exists("savedGroups", $cachedData) && is_array($cachedData['savedGroups'])) {
                    $this->setSavedGroups($cachedData['savedGroups']);
                }
                // A warm cache answering 304 is the common path, not an error one: returning
                // without this left the plugins unkeyed and dropping every event
                $this->initializePlugins();
                return;
            }

            $body = $res->getBody()->getContents();
            $parsed = json_decode($body, true);
            if (!$parsed || !is_array($parsed) || !array_key_exists("features", $parsed)) {
                $this->log(LogLevel::WARNING, "Could not load features", ["url" => $url, "responseBody" => $body]);
                $this->initializePlugins();
                return;
            }

            // Set features and savedGroups and cache for next time
            $features = array_key_exists("encryptedFeatures", $parsed)
                ? json_decode($this->decrypt($parsed["encryptedFeatures"]), true)
                : $parsed["features"];

            $savedGroups = array_key_exists("encryptedSavedGroups", $parsed)
                ? json_decode($this->decrypt($parsed["encryptedSavedGroups"]), true)
                : (array_key_exists("savedGroups", $parsed) ? $parsed["savedGroups"] : []);
            if (!is_array($savedGroups)) {
                $savedGroups = [];
            }

            // Store ETag for next conditional request
            $etag = $this->extractETagFromResponse($res);
            if ($etag !== null) {
                $this->etagCache->put($url, $etag);
            }


            $this->log(LogLevel::INFO, "Load features and saved groups from URL", ["url" => $url, "numFeatures" => count($features), "numGroups" => count($savedGroups)]);
            $this->setFeatures($features);
            $this->setSavedGroups($savedGroups);

            if ($this->cache) {
                $cacheData = [
                    'features' => $features,
                    'savedGroups' => $savedGroups,
                    'expiresAt' => time() + $this->cacheTTL
                ];
                $this->cache->set($cacheKey, json_encode($cacheData), $this->cacheTTL * 10);
                $this->log(LogLevel::INFO, "Cache features and saved groups", [
                    "url" => $url,
                    "numFeatures" => count($features),
                    "numGroups" => count($savedGroups),
                    "ttl" => $this->cacheTTL
                ]);
            }
        } catch (\Throwable $e) {
            $this->log(LogLevel::ERROR, "Failed to fetch features from API", [
                "url" => $url,
                "error" => $e->getMessage(),
                "errorClass" => get_class($e)
            ]);
            if ($cachedData !== null) {
                $this->log(LogLevel::WARNING, "Using stale cache as fallback", ["url" => $url]);
                if (array_key_exists("features", $cachedData) && is_array($cachedData['features'])) {
                    $this->setFeatures($cachedData['features']);
                }
                if (array_key_exists("savedGroups", $cachedData) && is_array($cachedData['savedGroups'])) {
                    $this->setSavedGroups($cachedData['savedGroups']);
                }
            }
        }

        $this->initializePlugins();
    }

    private function initializePlugins(): void
    {
        $this->pluginRegistry->initialize($this->clientKey);
    }

    /**
     * Re-fetch remotely evaluated features when the evaluation context changes.
     * No-op unless remote eval is enabled and the instance has already been initialized.
     * Backed by the response cache, so repeated contexts do not trigger network calls.
     *
     * @return void
     */
    private function refreshForRemoteEval(): void
    {
        if (!$this->remoteEval || !$this->clientKey) {
            return;
        }
        $this->fetchRemoteEval();
    }

    /**
     * Validate that the current configuration is compatible with remote evaluation.
     *
     * @return void
     * @throws Exception
     */
    private function validateRemoteEval(): void
    {
        if ($this->decryptionKey) {
            throw new Exception("Encryption is not available for remoteEval");
        }
        if ($this->stickyBucketService) {
            throw new Exception("stickyBucketService is not compatible with remoteEval; the proxy handles sticky bucketing server-side");
        }

        $host = empty($this->apiHost) ? self::DEFAULT_API_HOST : $this->apiHost;
        $hostname = parse_url($host, PHP_URL_HOST);
        if (is_string($hostname) && preg_match('/growthbook\.io$/i', $hostname)) {
            throw new Exception("Cannot use remoteEval on GrowthBook Cloud; use a self-hosted proxy/edge");
        }
    }

    /**
     * Build the POST body sent to the remote eval endpoint.
     *
     * @return array{attributes:array<string,mixed>,forcedFeatures:array<array{0:string,1:mixed}>,forcedVariations:array<string,int>,url:string}
     */
    private function buildRemoteEvalPayload(): array
    {
        $forcedFeatures = [];
        foreach ($this->forcedFeatures as $key => $value) {
            $forcedFeatures[] = [$key, $value->value];
        }

        return [
            "attributes" => RemoteSavedGroups::withIds($this->attributes, $this->remoteGroupIds),
            "forcedFeatures" => $forcedFeatures,
            "forcedVariations" => $this->forcedVariations,
            "url" => $this->url,
        ];
    }

    /**
     * Compute the cache key for a remote eval response. The key includes the
     * attributes (optionally narrowed by cacheKeyAttributes), forcedVariations and url.
     * forcedFeatures are deliberately excluded - they are applied client-side and the
     * proxy does not filter on them.
     *
     * @param string                                                                                                       $url
     * @param array{attributes:array<string,mixed>,forcedFeatures:array<array{0:string,1:mixed}>,forcedVariations:array<string,int>,url:string} $payload
     * @return string
     */
    private function getRemoteEvalCacheKey(string $url, array $payload): string
    {
        $attributes = $payload["attributes"];
        if ($this->cacheKeyAttributes !== null) {
            $ca = [];
            foreach ($this->cacheKeyAttributes as $attr) {
                $ca[$attr] = $attributes[$attr] ?? null;
            }
        } else {
            $ca = $attributes;
        }

        $keyData = [
            "ca" => $ca,
            "fv" => $payload["forcedVariations"],
            "url" => $payload["url"],
        ];

        // Sort keys recursively so the serialized key is canonical regardless of
        // attribute/forcedVariation insertion order. Matches Python (sort_keys=True)
        // and Java (TreeMap) - important because PHP caches are often shared across
        // processes (e.g. Redis), where insertion order is not guaranteed.
        self::ksortRecursive($keyData);

        return md5($url . "||" . json_encode($keyData) . "v2");
    }

    /**
     * Recursively sort an array by key (associative arrays only; lists are left as-is).
     *
     * @param array<mixed> $array
     * @return void
     */
    private static function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        unset($value);

        // Only reorder associative arrays; preserve the order of sequential lists
        if ($array !== [] && array_keys($array) !== range(0, count($array) - 1)) {
            ksort($array);
        }
    }

    /**
     * Apply a remote eval response (features + savedGroups) to the instance.
     *
     * @param array<string,mixed> $data
     * @param string              $source Human readable source for logging
     * @return void
     */
    private function applyRemoteEvalData(array $data, string $source): void
    {
        if (array_key_exists("features", $data) && is_array($data["features"])) {
            $this->log(LogLevel::INFO, "Load remote eval features from $source", ["numFeatures" => count($data["features"])]);
            $this->setFeatures($data["features"]);
        }
        if (array_key_exists("savedGroups", $data) && is_array($data["savedGroups"])) {
            $this->setSavedGroups($data["savedGroups"]);
        }
    }

    /**
     * Fetch remotely evaluated features from a self-hosted proxy/edge via POST.
     *
     * @return void
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    private function fetchRemoteEval(): void
    {
        $this->validateRemoteEval();

        $url = rtrim(empty($this->apiHost) ? self::DEFAULT_API_HOST : $this->apiHost, "/") . "/api/eval/" . $this->clientKey;
        $payload = $this->buildRemoteEvalPayload();
        $cacheKey = $this->getRemoteEvalCacheKey($url, $payload);

        // First try fetching from cache
        $cachedData = null;
        if ($this->cache) {
            $cachedResponse = $this->cache->get($cacheKey);
            if ($cachedResponse) {
                $decoded = json_decode($cachedResponse, true);
                if (is_array($decoded)) {
                    $isFresh = isset($decoded['expiresAt']) && $decoded['expiresAt'] > time();
                    if ($isFresh) {
                        $this->applyRemoteEvalData($decoded, "cache");
                        return;
                    }
                    $cachedData = $decoded;
                }
            }
        }

        if (!$this->httpClient || !$this->requestFactory) {
            if ($cachedData !== null) {
                $this->log(LogLevel::WARNING, "HTTP client not set, using stale cache as fallback", ["url" => $url]);
                $this->applyRemoteEvalData($cachedData, "stale cache");
            } else {
                $this->log(LogLevel::WARNING, "HTTP client or request factory not set, unable to load remote eval features");
            }
            return;
        }

        try {
            // attributes and forcedVariations must serialize as JSON objects ({}),
            // not arrays ([]), even when empty - the eval endpoint rejects arrays.
            // forcedFeatures is a list of [key, value] pairs and stays an array.
            $wirePayload = [
                "attributes" => (object) $payload["attributes"],
                "forcedFeatures" => $payload["forcedFeatures"],
                "forcedVariations" => (object) $payload["forcedVariations"],
                "url" => $payload["url"],
            ];
            $body = json_encode($wirePayload) ?: "{}";
            $req = $this->requestFactory->createRequest('POST', $url);
            $req = $req->withHeader('User-Agent', 'growthbook-php/' . $this->sdkVersion());
            $req = $req->withHeader('Content-Type', 'application/json');
            \assert($req instanceof \Psr\Http\Message\RequestInterface);

            $stream = $req->getBody();
            $stream->write($body);
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $res = $this->httpClient->sendRequest($req);
            $statusCode = $res->getStatusCode();

            if ($statusCode >= 400) {
                $this->log(LogLevel::WARNING, "Remote eval request failed", ["url" => $url, "statusCode" => $statusCode]);
                if ($cachedData !== null) {
                    $this->applyRemoteEvalData($cachedData, "stale cache");
                }
                return;
            }

            $responseBody = $res->getBody()->getContents();
            $parsed = json_decode($responseBody, true);
            if (!is_array($parsed) || !array_key_exists("features", $parsed)) {
                $this->log(LogLevel::WARNING, "Could not load remote eval features", ["url" => $url, "responseBody" => $responseBody]);
                if ($cachedData !== null) {
                    $this->applyRemoteEvalData($cachedData, "stale cache");
                }
                return;
            }

            $features = is_array($parsed["features"]) ? $parsed["features"] : [];
            $savedGroups = (array_key_exists("savedGroups", $parsed) && is_array($parsed["savedGroups"])) ? $parsed["savedGroups"] : [];

            $this->log(LogLevel::INFO, "Load remote eval features from URL", ["url" => $url, "numFeatures" => count($features), "numGroups" => count($savedGroups)]);
            $this->setFeatures($features);
            $this->setSavedGroups($savedGroups);

            if ($this->cache) {
                $cacheData = [
                    'features' => $features,
                    'savedGroups' => $savedGroups,
                    'expiresAt' => time() + $this->cacheTTL,
                ];
                $this->cache->set($cacheKey, json_encode($cacheData), $this->cacheTTL * 10);
            }
        } catch (\Throwable $e) {
            $this->log(LogLevel::ERROR, "Failed to fetch remote eval features from API", [
                "url" => $url,
                "error" => $e->getMessage(),
                "errorClass" => get_class($e),
            ]);
            if ($cachedData !== null) {
                $this->log(LogLevel::WARNING, "Using stale cache as fallback", ["url" => $url]);
                $this->applyRemoteEvalData($cachedData, "stale cache");
            }
        }
    }

    private static function sdkVersion(): string
    {
        try {
            return \Composer\InstalledVersions::getPrettyVersion('growthbook/growthbook') ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return string|null
     */
    private function extractETagFromResponse(\Psr\Http\Message\ResponseInterface $response): ?string
    {
        if (!$response->hasHeader('ETag')) {
            return null;
        }
        $etagHeader = $response->getHeader('ETag');
        return !empty($etagHeader) ? $etagHeader[0] : null;
    }

    /**
     * @deprecated Use initialize instead
     * @param string $clientKey
     * @param string $apiHost
     * @param string $decryptionKey
     * @return void
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function loadFeatures(string $clientKey, string $apiHost = "", string $decryptionKey = ""): void
    {
        $this->initialize($clientKey, $apiHost, $decryptionKey);
    }

    /**
     * Create HTTP client with safe default timeout settings
     * 
     * @return \Psr\Http\Client\ClientInterface
     */
    private function createDefaultHttpClient(): \Psr\Http\Client\ClientInterface
    {
        // Try to create Guzzle client with timeout if available
        if (class_exists(\GuzzleHttp\Client::class)) {
            $client = new \GuzzleHttp\Client([
                'timeout' => $this->apiTimeout,
                'connect_timeout' => $this->apiConnectTimeout,
            ]);

            /** @var \Psr\Http\Client\ClientInterface $client */
            return $client;
        }

        // Fallback to PSR-18 discovery
        // Note: discovered client may not have timeout configured
        $client = Psr18ClientDiscovery::find();

        $this->log(
            LogLevel::WARNING,
            "Using discovered HTTP client without guaranteed timeout. " .
            "Consider providing a configured client via withHttpClient()."
        );

        return $client;
    }
    /**
     * @param string                                                   $key
     * @param int|null                                                 $bucketVersion
     * @param int|null                                                 $minBucketVersion
     * @param array{key?:string,name?:string,passthrough?:bool}[]|null $meta
     * @param string|null                                              $hashAttribute
     * @param string|null                                              $fallbackAttribute
     * @return array{variation:int,versionIsBlocked?:bool}
     */
    private function getStickyBucketVariation(string $key, ?int $bucketVersion, ?int $minBucketVersion, ?array $meta, ?string $hashAttribute, ?string $fallbackAttribute): array
    {
        $bucketVersion = $bucketVersion ?? 0;
        $minBucketVersion = $minBucketVersion ?? 0;
        $meta = $meta ?? [];
        $hashAttribute = $hashAttribute ?: "id";
        $id = $this->getStickyBucketExperimentKey($key, $bucketVersion);
        $assignments = $this->getStickyBucketAssignments($hashAttribute, $fallbackAttribute);

        if ($minBucketVersion > 0) {

            for ($i = 0; $i < $minBucketVersion; $i++) {
                $blockedKey = $this->getStickyBucketExperimentKey($key, $i);

                if (array_key_exists($blockedKey, $assignments)) {
                    return [
                        "variation" => -1,
                        "versionIsBlocked" => true
                    ];
                }
            }
        }

        $variationKey = $assignments[$id] ?? null;
        if ($variationKey === null) {
            return [
                "variation" => -1,
            ];
        }
        $variation = -1;
        foreach ($meta as $i => $v) {
            if (isset($v['key']) && $v['key'] === $variationKey) {
                $variation = $i;
                break;
            }
        }

        if ($variation < 0) {
            return ['variation' => -1];
        }

        return ['variation' => $variation];
    }

    /**
     * @param string $experimentKey
     * @param int    $bucketVersion
     * @return string
     */
    private function getStickyBucketExperimentKey(string $experimentKey, int $bucketVersion = 0): string
    {
        return $experimentKey . "__" . $bucketVersion;
    }

    /**
     * @param string|null $hashAttribute
     * @param string|null $fallbackAttribute
     * @return array<string, string>
     */
    private function getStickyBucketAssignments(?string $hashAttribute = null, ?string $fallbackAttribute = null): array
    {
        $merged = [];
        list(, $hashValue) = $this->getHashValue($hashAttribute);
        $key = $hashAttribute . '||' . $hashValue;

        if (array_key_exists($key, $this->stickyBucketAssignmentDocs)) {
            $merged = $this->stickyBucketAssignmentDocs[$key]['assignments'];
        }

        if ($fallbackAttribute) {
            list(, $hashValue) = $this->getHashValue($fallbackAttribute);

            $key = $hashValue === "" ? "" : $fallbackAttribute . '||' . $hashValue;
            if (array_key_exists($key, $this->stickyBucketAssignmentDocs)) {
                foreach ($this->stickyBucketAssignmentDocs[$key]['assignments'] as $key => $value) {
                    if (!array_key_exists($key, $merged)) {
                        $merged[$key] = $value;
                    }
                }
            }
        }
        return $merged;
    }

    /**
     * @param string                $hashAttribute
     * @param string                $hashValue
     * @param array<string, string> $assignments
     * @return array{key:string,doc:array,changed:bool}
     */
    private function generateStickyBucketAssignmentDoc(string $hashAttribute, string $hashValue, array $assignments): array
    {
        $key = $hashAttribute . '||' . $hashValue;
        $doc = $this->stickyBucketAssignmentDocs[$key] ?? null;
        $existingAssignments = [];
        if (!is_null($doc)) {
            $existingAssignments = $doc['assignments'];
        }

        $newAssignments = array_merge($existingAssignments, $assignments);
        $existingJson = json_encode($existingAssignments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
        $newJson = json_encode($newAssignments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
        $changed = $existingJson !== $newJson;

        return [
            'key' => $key,
            'doc' => [
                'attributeName' => $hashAttribute,
                'attributeValue' => $hashValue,
                'assignments' => $newAssignments
            ],
            'changed' => $changed
        ];
    }

    /**
     * @param bool $force
     * @return void
     */
    private function refreshStickyBuckets(bool $force = false): void
    {
        if (!$this->stickyBucketService) {
            return;
        }

        $attributes = $this->getStickyBucketAttributes();

        if (!$force && $attributes === $this->stickyBucketAttributes) {
            $this->log(LogLevel::DEBUG, "Skipping refresh of sticky bucket assignments, no changes");
            return;
        }

        $this->stickyBucketAttributes = $attributes;
        $this->stickyBucketAssignmentDocs = $this->stickyBucketService->getAllAssignments($attributes);
    }

    /**
     * @return array<string, string>
     */
    private function getStickyBucketAttributes(): array
    {
        $attributes = [];

        if ($this->usingDerivedStickyBucketAttributes) {
            $this->stickyBucketIdentifierAttributes = $this->deriveStickyBucketIdentifierAttributes();
        }

        if (!$this->stickyBucketIdentifierAttributes) {
            return $attributes;
        }

        foreach ($this->stickyBucketIdentifierAttributes as $attr) {
            list(, $hashValue) = $this->getHashValue($attr);

            if ($hashValue !== "") {
                $attributes[$attr] = $hashValue;
            }
        }

        return $attributes;
    }

    /**
     * @return array<string>
     */
    private function deriveStickyBucketIdentifierAttributes(): array
    {
        $attributes = [];

        foreach ($this->features as $key => $feature) {
            foreach ($feature->rules as $rule) {
                if (!empty($rule->variations)) {
                    $attributes[] = $rule->hashAttribute ?? "id";
                    if (!empty($rule->fallbackAttribute)) {
                        $attributes[] = $rule->fallbackAttribute;
                    }
                }
            }
        }

        return array_unique($attributes);
    }
}
