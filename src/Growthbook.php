<?php

namespace Growthbook;

use Error;
use Exception;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;
use function React\Promise\resolve;
use function React\Promise\Timer\timeout;

/**
 * Class Growthbook
 *
 * This class manages feature flagging, experiment assignment, and caching.
 */
class Growthbook implements LoggerAwareInterface
{
    private const DEFAULT_API_HOST = "https://cdn.growthbook.io";

    /** @var bool */
    public $enabled = true;

    /** @var LoggerInterface|null */
    public $logger = null;

    /** @var string */
    private $url = "";

    /** @var array<string,mixed> */
    private $attributes = [];

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

    /** @var CacheInterface|null */
    private $cache = null;

    /** @var int */
    private $cacheTTL = 60;

    /**
     * Because $httpClient can be either a PSR-18 ClientInterface or React\Http\Browser,
     * we handle synchronous and asynchronous requests differently.
     *
     * @var ClientInterface|Browser|null
     */
    private $httpClient;

    /** @var RequestFactoryInterface|null */
    public $requestFactory;

    /** @var string */
    private $apiHost = "";

    /** @var string */
    private $clientKey = "";

    /** @var string */
    private $decryptionKey = "";

    /** @var array<string, ViewedExperiment> */
    private $tracks = [];

    /** @var StickyBucketService|null */
    private $stickyBucketService = null;

    /** @var array<string>|null */
    private $stickyBucketIdentifierAttributes = null;
    /** @var array<string, array> */
    private $stickyBucketAssignmentDocs = [];
    /** @var bool */
    private $usingDerivedStickyBucketAttributes;
    /** @var null|array<string, string> */
    private $stickyBucketAttributes = null;

    /** @var LoopInterface */
    private $loop;

    /** @var Browser */
    private $asyncClient;

    /**
     * Non-generic typehint, since React\Promise\PromiseInterface is not generic.
     *
     * @var PromiseInterface<mixed>|null
     */
    public $promise;

    /**
     * Creates an instance of Growthbook.
     * @param array{
     *   enabled?: bool,
     *   logger?: LoggerInterface,
     *   url?: string,
     *   attributes?: array<string,mixed>,
     *   features?: array<string,mixed>,
     *   forcedVariations?: array<string,int>,
     *   forcedFeatures?: array<string, FeatureResult<mixed>>,
     *   qaMode?: bool,
     *   trackingCallback?: callable,
     *   cache?: CacheInterface,
     *   httpClient?: ClientInterface|Browser,
     *   requestFactory?: RequestFactoryInterface,
     *   decryptionKey?: string,
     *   loop?: LoopInterface
     * } $options
     * @return Growthbook
     */
    public static function create(array $options = []): Growthbook
    {
        return new Growthbook($options);
    }

    /**
     * @param array{
     *   enabled?: bool,
     *   logger?: LoggerInterface,
     *   url?: string,
     *   attributes?: array<string,mixed>,
     *   features?: array<string,mixed>,
     *   forcedVariations?: array<string,int>,
     *   forcedFeatures?: array<string, FeatureResult<mixed>>,
     *   qaMode?: bool,
     *   trackingCallback?: callable,
     *   cache?: CacheInterface,
     *   httpClient?: ClientInterface|Browser,
     *   requestFactory?: RequestFactoryInterface,
     *   decryptionKey?: string,
     *   loop?: LoopInterface
     * } $options
     */
    public function __construct(array $options = [])
    {
        // We do not mark resolve(null) as "PromiseInterface<mixed>" in docblocks
        // because React\Promise\PromiseInterface is not generic.
        $this->promise = resolve(null);

        // Known config options for error-checking
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
            "loop"
        ];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error(
                'Unknown Config options: ' . implode(", ", $unknownOptions),
                E_USER_NOTICE
            );
        }

        $this->enabled = $options["enabled"] ?? true;
        $this->logger = $options["logger"] ?? null;
        $this->url = $options["url"] ?? ($_SERVER['REQUEST_URI'] ?? "");
        $this->forcedVariations = $options["forcedVariations"] ?? [];
        $this->qaMode = $options["qaMode"] ?? false;
        $this->trackingCallback = $options["trackingCallback"] ?? null;
        $this->decryptionKey = $options["decryptionKey"] ?? "";
        $this->cache = $options["cache"] ?? null;

        // ReactPHP EventLoop (used for async calls)
        $this->loop = $options['loop'] ?? Loop::get();
        $this->asyncClient = new Browser($this->loop);

        $this->httpClient = $options["httpClient"] ?? null;
        $this->requestFactory = $options["requestFactory"] ?? null;

        // Try discovering PSR-18 and PSR-17 implementations if not supplied
        if (!$this->httpClient) {
            try {
                $this->httpClient = Psr18ClientDiscovery::find();
            } catch (Throwable $e) {
                // If no PSR-18 client is found, it remains null
            }
        }
        if (!$this->requestFactory) {
            try {
                $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
            } catch (Throwable $e) {
                // If no request factory is found, it remains null
            }
        }

        $this->stickyBucketService = $options["stickyBucketService"] ?? null;
        $this->stickyBucketIdentifierAttributes = $options["stickyBucketIdentifierAttributes"] ?? null;
        $this->usingDerivedStickyBucketAttributes = !isset($this->stickyBucketIdentifierAttributes);

        // Check if the httpClient is valid
        if (!($this->httpClient instanceof ClientInterface) && !($this->httpClient instanceof Browser)) {
            throw new InvalidArgumentException("httpClient must be an instance of ClientInterface or Browser");
        }
        // Check if the requestFactory is valid
        if (!($this->requestFactory instanceof RequestFactoryInterface)) {
            throw new InvalidArgumentException("requestFactory must be an instance of RequestFactoryInterface");
        }

        // Forced features
        if (array_key_exists("forcedFeatures", $options)) {
            $this->withForcedFeatures($options['forcedFeatures']);
        }
        // Features
        if (array_key_exists("features", $options)) {
            $this->withFeatures($options["features"]);
        }
        // Attributes
        if (array_key_exists("attributes", $options)) {
            $this->withAttributes($options["attributes"]);
        }
    }

    /**
     * @param array<string,mixed> $attributes
     * @return $this
     */
    public function withAttributes(array $attributes): self
    {
        $this->attributes = $attributes;
        $this->refreshStickyBuckets();
        return $this;
    }

    /**
     * @param callable|null $trackingCallback
     * @return $this
     */
    public function withTrackingCallback($trackingCallback): self
    {
        $this->trackingCallback = $trackingCallback;
        return $this;
    }

    /**
     * @param array<string,Feature<mixed>|mixed> $features
     * @return $this
     */
    public function withFeatures(array $features): self
    {
        $this->features = [];
        foreach ($features as $key => $feature) {
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
     * @param array<string,int> $forcedVariations
     * @return $this
     */
    public function withForcedVariations(array $forcedVariations): self
    {
        $this->forcedVariations = $forcedVariations;
        return $this;
    }

    /**
     * @param array<string, FeatureResult<mixed>> $forcedFeatures
     * @return $this
     */
    public function withForcedFeatures(array $forcedFeatures): self
    {
        $this->forcedFeatures = $forcedFeatures;
        return $this;
    }

    /**
     * @param string $url
     * @return $this
     */
    public function withUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * @return $this
     */
    public function withLogger(?LoggerInterface $logger = null): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function setLogger(?LoggerInterface $logger = null): void
    {
        $this->logger = $logger;
    }

    /**
     * @return $this
     */
    public function withHttpClient(ClientInterface $client, ?RequestFactoryInterface $requestFactory = null): self
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;
        return $this;
    }

    /**
     * @return $this
     */
    public function withCache(CacheInterface $cache, ?int $ttl = null): self
    {
        $this->cache = $cache;
        if ($ttl !== null) {
            $this->cacheTTL = $ttl;
        }
        return $this;
    }

    /**
     * @param StickyBucketService $stickyBucketService
     * @param array<string>|null  $stickyBucketIdentifierAttributes
     * @return $this
     */
    public function withStickyBucketing(StickyBucketService $stickyBucketService, ?array $stickyBucketIdentifierAttributes): Growthbook
    {
        $this->stickyBucketService = $stickyBucketService;
        $this->stickyBucketIdentifierAttributes = $stickyBucketIdentifierAttributes;
        $this->usingDerivedStickyBucketAttributes = !isset($this->stickyBucketIdentifierAttributes);

        return $this;
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
    public function getForcedFeatured(): array
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
    public function getTrackingCallback()
    {
        return $this->trackingCallback;
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
     * @param T $default
     * @return T
     */
    public function getValue(string $key, $default)
    {
        $res = $this->getFeature($key);
        return $res->value ?? $default;
    }

    /**
     * @param array<array<string, mixed>> $parentConditions
     * @param array<string> $stack
     * @return string
     */
    private function evalPrereqs(array $parentConditions, array $stack): string
    {
        foreach ($parentConditions as $parentCondition) {
            $parentRes = $this->getFeature($parentCondition['id'] ?? null, $stack);

            if ($parentRes->source === "cyclicPrerequisite") {
                return "cyclic";
            }

            if (!Condition::evalCondition(['value' => $parentRes->value], $parentCondition['condition'] ?? null)) {
                if ($parentCondition['gate'] ?? false) {
                    return "gate";
                }
                return "fail";
            }
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
        if (!array_key_exists($key, $this->features)) {
            $this->log(LogLevel::DEBUG, "Unknown feature - $key");
            return new FeatureResult(null, "unknownFeature");
        }
        $this->log(LogLevel::DEBUG, "Evaluating feature - $key");
        $feature = $this->features[$key];

        if(in_array($key, $stack)) {
            $this->log(LogLevel::WARNING, "Cyclic prerequisite detected, stack", [
                "stack" => $stack,
            ]);
            return new FeatureResult(null, "cyclicPrerequisite");
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
                        return new FeatureResult(null, "prerequisite");
                    }
                    if ($prereqRes === 'cyclic') {
                        return new FeatureResult(null, "cyclicPrerequisite");
                    }
                    if ($prereqRes === 'fail') {
                        $this->log(LogLevel::DEBUG, "Skip rule because of failing prerequisite, feature", [
                            "feature" => $key,
                        ]);
                        continue;
                    }
                }

                if ($rule->condition) {
                    if (!Condition::evalCondition($this->attributes, $rule->condition)) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of targeting condition", [
                            "feature" => $key,
                            "condition" => $rule->condition
                        ]);
                        continue;
                    }
                }
                if ($rule->filters) {
                    if ($this->isFilteredOut($rule->filters)) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of filtering (e.g. namespace)", [
                            "feature" => $key,
                            "filters" => $rule->filters
                        ]);
                        continue;
                    }
                }

                // If forced
                if (isset($rule->force)) {
                    if (!$this->isIncludedInRollout(
                        $rule->seed ?? $key,
                        $rule->hashAttribute,
                        $rule->fallbackAttribute,
                        $rule->range,
                        $rule->coverage,
                        $rule->hashVersion
                    )) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of rollout percent", [
                            "feature" => $key
                        ]);
                        continue;
                    }
                    $this->log(LogLevel::DEBUG, "Force feature value from rule", [
                        "feature" => $key,
                        "value" => $rule->force
                    ]);
                    return new FeatureResult($rule->force, "force");
                }

                // Convert to experiment
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
                return new FeatureResult($result->value, "experiment", $exp, $result);
            }
        }
        return new FeatureResult($feature->defaultValue ?? null, "defaultValue");
    }

    /**
     * @template T
     * @param InlineExperiment<T> $exp
     * @return ExperimentResult<T>
     */
    public function runInlineExperiment(InlineExperiment $exp): ExperimentResult
    {
        return $this->runExperiment($exp, null);
    }

    /**
     * @template T
     * @param InlineExperiment<T> $exp
     * @param string|null $featureId
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

        list($hashAttribute, $hashValue) = $this->getHashValue($exp->hashAttribute, $exp->fallbackAttribute);
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
        if (!$hashValue) {
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
            if ($exp->filters && $this->isFilteredOut($exp->filters)) {
                $this->log(LogLevel::DEBUG, "Skip experiment : filtered out", [
                    "experiment" => $exp->key
                ]);
                return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
            }
        }
        // Ignore the namespace if there are filters
        if (!$exp->filters && $exp->namespace && !static::inNamespace($hashValue, $exp->namespace)) {
            $this->log(LogLevel::DEBUG, "Skip experiment because not in namespace", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
        }

        // 8. Condition fails
        if ($exp->condition && !Condition::evalCondition($this->attributes, $exp->condition)) {
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


        // 11. Force variation in experiment config
        if ($exp->force !== null) {
            $this->log(LogLevel::DEBUG, "Force variation from the experiment config", [
                "experiment" => $exp->key,
                "variation" => $exp->force
            ]);
            return new ExperimentResult($exp, $hashAttribute, $hashValue, $exp->force, false, $featureId);
        }

        // 12. QA mode
        if ($this->qaMode) {
            $this->log(LogLevel::DEBUG, "Skip experiment because Growthbook instance is in QA Mode", [
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
        $this->tracks[$exp->key] = new ViewedExperiment($exp, $result);
        if ($this->trackingCallback) {
            try {
                call_user_func($this->trackingCallback, $exp, $result);
            } catch (Throwable $e) {
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
     * @param array<mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Hash function with 2 different versions:
     * v2: no known bias (fnv1a32->hexdec->mod)
     * v1: older approach with slight bias
     *
     * @return float|null
     */
    public static function hash(string $seed, string $value, int $version): ?float
    {
        // Version 2 hashing
        if ($version === 2) {
            $n = hexdec(hash("fnv1a32", hexdec(hash("fnv1a32", $seed . $value)) . ""));
            return ($n % 10000) / 10000;
        }
        // Version 1 hashing
        if ($version === 1) {
            $n = hexdec(hash("fnv1a32", $value . $seed));
            return ($n % 1000) / 1000;
        }

        return null;
    }

    /**
     * Helper to check if a number is within a range
     *
     * @param float $n
     * @param array{0:float,1:float} $range
     * @return bool
     */
    public static function inRange(float $n, array $range): bool
    {
        return $n >= $range[0] && $n < $range[1];
    }

    /**
     * Check if user is included in a rollout
     *
     * @param string      $seed
     * @param string|null $hashAttribute
     * @param array{0:float,1:float}|null $range
     * @param float|null  $coverage
     * @param int|null    $hashVersion
     * @return bool
     */
    private function isIncludedInRollout(
        string $seed,
        ?string $hashAttribute = null,
        ?string $fallbackAttribute = null,
        ?array $range = null,
        ?float $coverage = null,
        ?int $hashVersion = null
    ): bool {
        if ($coverage === null && $range === null) {
            return true;
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
            return $n <= $coverage;
        }

        return true;
    }

    /**
     * @param string|null $hashAttribute
     * @param string|null $fallbackAttribute
     * @return array{0:string,1:string}
     */
    private function getHashValue(?string $hashAttribute = null, ?string $fallbackAttribute = null): array
    {
        $attribute = $hashAttribute ?? "id";
        $val = "";

        if (array_key_exists($attribute, $this->attributes)) {
            $val = $this->attributes[$attribute] ?? "";
        }

        if (($val === "" || $val === null) && $fallbackAttribute && $this->stickyBucketService) {

            if (array_key_exists($fallbackAttribute, $this->attributes)) {
                $val = $this->attributes[$fallbackAttribute] ?? "";
            }

            if (!empty($val) || $val != "") {
                $attribute = $fallbackAttribute;
            }
        }

        return [$attribute, strval($val)];
    }

    /**
     * @param array<array{
     *   seed:string,
     *   ranges:array<array{0:float,1:float}>,
     *   hashVersion?:int,
     *   attribute?:string
     * }> $filters
     * @return bool
     */
    private function isFilteredOut(array $filters): bool
    {
        foreach ($filters as $filter) {
            list(, $hashValue) = $this->getHashValue($filter["attribute"] ?? "id");
            if ($hashValue === "") {
                // If there's no attribute to hash, can't filter user out,
                // so the user is effectively included (return false).
                continue; // Skip if hash value is empty
            }

            $n = self::hash($filter["seed"] ?? "", $hashValue, $filter["hashVersion"] ?? 2);
            if ($n === null) {
                continue; // Skip if hash computation fails
            }

            $matched = false;
            foreach ($filter["ranges"] as $range) {
                if (self::inRange($n, $range)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return true; // Filter out if no range matches
            }
        }

        return false; // All filters passed
    }

    /**
     * Check if user is in a namespace
     *
     * @param string $userId
     * @param array{0:string,1:float,2:float} $namespace
     * @return bool
     */
    public static function inNamespace(string $userId, array $namespace): bool
    {
        // Namespace must have 3 elements: [seed, start, end]
        // @phpstan-ignore-next-line
        if (count($namespace) !== 3) {
            return false;
        }
        // Calculate the hash
        $n = static::hash("__" . $namespace[0], $userId, 1);
        if ($n === null) {
            return false;
        }
        // Check if the hash is in the specified range
        return $n >= $namespace[1] && $n < $namespace[2];
    }

    /**
     * Helper to get an array of equal weights
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
     * Build bucket ranges for each variation
     *
     * @param int          $numVariations
     * @param float        $coverage
     * @param float[]|null $weights
     * @return array<array{0:float,1:float}>
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
     * Determine which variation a user is bucketed into
     *
     * @param float                             $n
     * @param array<array{0:float,1:float}>     $ranges
     * @return int
     */
    public static function chooseVariation(float $n, array $ranges): int
    {
        foreach ($ranges as $i => $range) {
            if (self::inRange($n, $range)) {
                return (int)$i;
            }
        }
        return -1;
    }

    /**
     * For overriding experiment variations via query string
     *
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
        $variation = (int)$params[$id];
        if ($variation < 0 || $variation >= $numVariations) {
            return null;
        }

        return $variation;
    }

    /**
     * Decrypt features from an encrypted string
     *
     * @param string $encryptedString
     * @return string
     */
    public function decrypt(string $encryptedString): string
    {
        if (!$this->decryptionKey) {
            throw new Error("Must specify a decryption key in order to use encrypted feature flags");
        }
        $parts = explode(".", $encryptedString, 2);
        $iv = base64_decode($parts[0]);
        $cipherText = $parts[1];

        $password = base64_decode($this->decryptionKey);

        $decrypted = openssl_decrypt($cipherText, "aes-128-cbc", $password, 0, $iv);
        if (!$decrypted) {
            throw new Error("Failed to decrypt");
        }

        return $decrypted;
    }

    /**
     * Load features (async or sync)
     *
     * @param array{
     *   async?: bool,
     *   skipCache?: bool,
     *   staleWhileRevalidate?: bool,
     *   timeout?: int
     * } $options
     * @return PromiseInterface<mixed>
     */
    public function loadFeatures(
        string $clientKey = "",
        string $apiHost = "",
        string $decryptionKey = "",
        array  $options = []
    ): PromiseInterface {
        $this->clientKey = $clientKey;
        $this->apiHost = $apiHost;
        $this->decryptionKey = $decryptionKey;

        if (!$this->clientKey) {
            throw new Exception("Must specify a clientKey before loading features.");
        }
        if (!$this->httpClient) {
            throw new Exception("Must set an HTTP Client before loading features.");
        }
        if (!$this->requestFactory) {
            throw new Exception("Must set an HTTP Request Factory before loading features");
        }

        $isAsync = $options['async'] ?? false;
        if ($isAsync) {
            $this->promise = $this->loadFeaturesAsyncInternal($options);
            return $this->promise;
        }

        // The features URL is also the cache key
        $url = rtrim(empty($this->apiHost) ? self::DEFAULT_API_HOST : $this->apiHost, "/") . "/api/features/" . $this->clientKey;
        // Synchronous fetch
        $this->loadFeaturesSyncInternal($options);
        // We set promise to a resolved promise to avoid null
        $this->promise = resolve(null);
        return $this->promise;
    }

    /**
     * Synchronous version of fetching features
     *
     * @param array{
     *   async?: bool,
     *   skipCache?: bool,
     *   staleWhileRevalidate?: bool,
     *   timeout?: int
     * } $options
     */
    private function loadFeaturesSyncInternal(array $options): void
    {
        $timeout = $options['timeout'] ?? null;
        $skipCache = $options['skipCache'] ?? false;
        $staleWhileRevalidate = $options['staleWhileRevalidate'] ?? true;

        $url = rtrim($this->apiHost ?: self::DEFAULT_API_HOST, "/")
            . "/api/features/" . $this->clientKey;
        $cacheKey = md5($url);
        $now = time();

        // If we have cache & skipCache is false, attempt to load from cache
        if ($this->cache && !$skipCache) {
            $cachedData = $this->cache->get($cacheKey);
            $cachedTime = $this->cache->get($cacheKey . '_time');
            if ($cachedData) {
                $features = json_decode($cachedData, true);
                if (is_array($features)) {
                    $age = $cachedTime ? ($now - (int)$cachedTime) : PHP_INT_MAX;
                    if ($age < $this->cacheTTL) {
                        // Cache is fresh
                        $this->log(LogLevel::INFO, "Load features from cache (sync)", [
                            "url" => $url,
                            "numFeatures" => count($features),
                        ]);
                        $this->withFeatures($features);
                        return;
                    } else {
                        // Cache is stale
                        if ($staleWhileRevalidate) {
                            $this->log(LogLevel::INFO, "Load stale features from cache, then revalidate (sync)", [
                                "url" => $url,
                                "numFeatures" => count($features),
                            ]);
                            $this->withFeatures($features);

                            // Fetch fresh data
                            $this->revalidateFeaturesInBackground($url, $cacheKey, $timeout);
                            return;
                        } else {
                            $this->log(LogLevel::INFO, "Cache stale, fetch new features (sync)", ["url" => $url]);
                        }
                    }
                }
            }
        }

        // If no cache or no valid data found, fetch fresh from server
        $fresh = $this->fetchFeaturesSync($url);
        if ($fresh !== null) {
            $this->storeFeaturesInCache($fresh, $cacheKey);
        } else {
            $this->getFeaturesFromCache($cacheKey, $url,  $skipCache);
        }
    }

    /**
     * Revalidates features in the background using an asynchronous fetch.
     *
     * @param string $url The URL to fetch features from.
     * @param string $cacheKey The cache key to store the fetched features.
     * @param int|null $timeout Optional timeout for the fetch operation.
     * @return void
     */
    private function revalidateFeaturesInBackground(string $url, string $cacheKey, ?int $timeout): void
    {
        $this->loop->addTimer(0.01, function () use ($url, $cacheKey, $timeout) {
            $this->asyncFetchFeatures($url, $timeout)->then(function ($features) use ($cacheKey) {
                $this->withFeatures($features);
                $this->storeFeaturesInCache($features, $cacheKey);
            })->catch(function ($e) {
                $this->log(LogLevel::WARNING, "Background revalidation failed", ["error" => $e->getMessage()]);
            });
        });
    }

    /**
     * Attempt to load features from cache if available.
     *
     * @param string $cacheKey The cache key to use for retrieving cached features.
     * @param string $url The URL to log in case of cache miss or error.
     * @param bool $skipCache Whether to skip cache and force a fresh fetch.
     */
    private function getFeaturesFromCache(string $cacheKey, string $url, bool $skipCache): void {
        // Try to get features from cache if available
        if ($this->cache && !$skipCache) {
            try {
                $featuresJSON = $this->cache->get($cacheKey);
                if ($featuresJSON) {
                    $features = json_decode($featuresJSON, true);
                    if ($features && is_array($features)) {
                        $this->log(LogLevel::WARNING, "Using possibly stale features from cache due to exception", ["url" => $url, "numFeatures" => count($features)]);
                        $this->withFeatures($features);
                    }
                }
            } catch (\Throwable $e) {
                $this->log(LogLevel::ERROR, "Error loading features from cache after API exception", ["exception" => $e]);
            }
        }
    }

    /**
     * Asynchronous version of fetching features
     *
     * @param array{
     *   async?: bool,
     *   skipCache?: bool,
     *   staleWhileRevalidate?: bool,
     *   timeout?: int
     * } $options
     * @return PromiseInterface<mixed>
     */
    private function loadFeaturesAsyncInternal(array $options): PromiseInterface
    {
        $timeout = $options['timeout'] ?? null;
        $skipCache = $options['skipCache'] ?? false;
        $staleWhileRevalidate = $options['staleWhileRevalidate'] ?? true;

        $url = rtrim($this->apiHost ?: self::DEFAULT_API_HOST, "/") . "/api/features/" . $this->clientKey;
        $cacheKey = md5($url);
        $now = time();

        // Attempt to load from cache if available
        if ($this->cache && !$skipCache) {
            $cachedData = $this->cache->get($cacheKey);
            $cachedTime = $this->cache->get($cacheKey . '_time');
            if ($cachedData) {
                $features = json_decode($cachedData, true);
                if (is_array($features)) {
                    $age = $cachedTime ? ($now - (int)$cachedTime) : PHP_INT_MAX;
                    if ($age < $this->cacheTTL) {
                        $this->log(LogLevel::INFO, "Load features from cache (async)", [
                            "url" => $url,
                            "numFeatures" => count($features),
                        ]);
                        $this->withFeatures($features);
                        return resolve($features);
                    }
                    // If stale is allowed, serve stale & revalidate
                    if ($staleWhileRevalidate) {
                        $this->log(LogLevel::INFO, "Load stale features from cache, then revalidate (async)", [
                            "url" => $url,
                            "numFeatures" => count($features),
                        ]);
                        $this->withFeatures($features);

                        // Return a promise that tries to fetch fresh data
                        /** @var PromiseInterface<array<string,mixed>> $updatePromise */
                        $updatePromise = $this->asyncFetchFeatures($url, $timeout)
                            ->then(function (array $fresh) use ($cacheKey) {
                                $this->storeFeaturesInCache($fresh, $cacheKey);
                                return $fresh;
                            })
                            ->then(
                                null,
                                function (Throwable $e) {
                                    $this->log(LogLevel::WARNING, "Revalidation failed (async)", [
                                        "error" => $e->getMessage()
                                    ]);
                                    return $this->features;
                                }
                            )->catch(
                                function (Throwable $e) {
                                    $this->log(LogLevel::ERROR, "Async fetch failed: " . $e->getMessage());
                                    throw $e;
                                }
                            );
                        return $updatePromise;
                    }

                    $this->log(LogLevel::INFO, "Cache stale, fetch new features (async)", ["url" => $url]);
                }
            }
        }

        // No valid cache or skipCache=true => fetch from server
        /** @var PromiseInterface<array<string,mixed>> $promise */
        $promise = $this->asyncFetchFeatures($url, $timeout)
            ->then(function (array $fresh) use ($cacheKey) {
                $this->storeFeaturesInCache($fresh, $cacheKey);
                return $fresh;
            });
        return $promise;
    }

    /**
     * Fetch features asynchronously with React\Http\Browser and optional timeout.
     *
     * @param string   $url
     * @param int|null $timeout
     * @return PromiseInterface<array<string,mixed>>
     */
    private function asyncFetchFeatures(string $url, ?int $timeout): PromiseInterface
    {
        // Browser->get() returns a PromiseInterface<ResponseInterface>
        /** @var PromiseInterface<ResponseInterface> $request */
        $request = $this->asyncClient->get($url);

        // Wrap with timeout if needed
        if ($timeout !== null && $timeout > 0) {
            /** @var PromiseInterface<ResponseInterface> $request */
            $request = timeout($request, $timeout, $this->loop);
        }

        return $request->then(function (ResponseInterface $response) use ($url) {
            $body = (string)$response->getBody();
            $parsed = json_decode($body, true);
            if (!$parsed || !is_array($parsed) || !isset($parsed['features'])) {
                $this->log(LogLevel::WARNING, "Could not load features (async)", [
                    "url" => $url,
                    "responseBody" => $body
                ]);
                throw new RuntimeException("Invalid features response");
            }
            $features = isset($parsed["encryptedFeatures"])
                ? json_decode($this->decrypt($parsed["encryptedFeatures"]), true)
                : $parsed["features"];

            $this->log(LogLevel::INFO, "Async load features from URL", [
                "url" => $url,
                "numFeatures" => count($features)
            ]);
            $this->withFeatures($features);

            return $features;
        });
    }

    /**
     * Fetch features synchronously (PSR-18) or throw if Browser is used.
     *
     * @param string $url
     * @return array<string,mixed>|null
     */
    private function fetchFeaturesSync(string $url): ?array
    {
        if (!$this->requestFactory) {
            throw new RuntimeException("RequestFactory is null");
        }
        if (!$this->httpClient) {
            throw new RuntimeException("HttpClient is null");
        }

        $req = $this->requestFactory->createRequest('GET', $url);

        // If it's a PSR-18 client, we can do sendRequest()
        if ($this->httpClient instanceof ClientInterface) {
            try {
                $res = $this->httpClient->sendRequest($req);
                $body = (string)$res->getBody();
                $parsed = json_decode($body, true);

                if (!$parsed || !is_array($parsed) || !isset($parsed['features'])) {
                    $this->log(LogLevel::WARNING, "Could not load features (sync)", [
                        "url" => $url,
                        "responseBody" => $body,
                    ]);
                    return null;
                }

                $features = isset($parsed["encryptedFeatures"])
                    ? json_decode($this->decrypt($parsed["encryptedFeatures"]), true)
                    : $parsed["features"];

                $this->log(LogLevel::INFO, "Load features from URL (sync)", [
                    "url" => $url,
                    "numFeatures" => count($features),
                ]);
                $this->withFeatures($features);
                return $features;
            } catch (Throwable $e) {
                $this->log(LogLevel::ERROR, "Exception while loading features from API", [
                    "url" => $url,
                    "exception" => $e->getMessage(),
                ]);
                return null;
            }
        } elseif ($this->httpClient instanceof Browser) {
            // If it's React Browser, synchronous usage is not typical
            throw new RuntimeException("Synchronous requests are not supported when using React\\Http\\Browser");
        } else {
            throw new RuntimeException("Unsupported HTTP client");
        }
    }

    /**
     * Store fetched features in cache
     *
     * @param array<string,mixed> $features
     * @param string              $cacheKey
     * @return bool
     */
    private function storeFeaturesInCache(array $features, string $cacheKey): bool
    {
        if ($this->cache) {
            $success1 = $this->cache->set($cacheKey, json_encode($features), $this->cacheTTL);
            $success2 = $this->cache->set($cacheKey . '_time', time(), $this->cacheTTL);
            $this->log(LogLevel::INFO, "Cache features", [
                "numFeatures" => count($features),
                "ttl" => $this->cacheTTL
            ]);
            return $success1 && $success2;
        }
        return true;
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
        if (!$variationKey) {
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

            $key = $fallbackAttribute . '||' . $hashValue;
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

            if ($hashValue) {
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
