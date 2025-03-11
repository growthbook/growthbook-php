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

    /** @var bool */
    public $enabled = true;
    /** @var null|\Psr\Log\LoggerInterface */
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

    /** @var array<string>|null */
    private $stickyBucketIdentifierAttributes = null;
    /** @var array<string, array> */
    private $stickyBucketAssignmentDocs = [];
    /** @var bool */
    private $usingDerivedStickyBucketAttributes;
    /** @var null|array<string, string> */
    private $stickyBucketAttributes = null;

    public static function create(): Growthbook
    {
        return new Growthbook();
    }

    /**
     * @param array{enabled?:bool,logger?:\Psr\Log\LoggerInterface,url?:string,attributes?:array<string,mixed>,features?:array<string,mixed>,forcedVariations?:array<string,int>,qaMode?:bool,trackingCallback?:callable,cache?:\Psr\SimpleCache\CacheInterface,httpClient?:\Psr\Http\Client\ClientInterface,requestFactory?:\Psr\Http\Message\RequestFactoryInterface,decryptionKey?:string,forcedFeatures?:array<string, FeatureResult<mixed>>} $options
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
            "stickyBucketIdentifierAttributes"
        ];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error('Unknown Config options: ' . implode(", ", $unknownOptions), E_USER_NOTICE);
        }

        $this->enabled = $options["enabled"] ?? true;
        $this->logger = $options["logger"] ?? null;
        $this->url = $options["url"] ?? $_SERVER['REQUEST_URI'] ?? "";
        $this->forcedVariations = $options["forcedVariations"] ?? [];
        $this->qaMode = $options["qaMode"] ?? false;
        $this->trackingCallback = $options["trackingCallback"] ?? null;

        $this->decryptionKey = $options["decryptionKey"] ?? "";

        $this->cache = $options["cache"] ?? null;
        try {
            $this->httpClient = $options["httpClient"] ?? Psr18ClientDiscovery::find();
            $this->requestFactory = $options["requestFactory"] ?? Psr17FactoryDiscovery::findRequestFactory();
        } catch (\Throwable $e) {
            // Ignore errors from discovery
        }

        $this->stickyBucketService = $options["stickyBucketService"] ?? null;
        $this->stickyBucketIdentifierAttributes = $options["stickyBucketIdentifierAttributes"] ?? null;
        $this->usingDerivedStickyBucketAttributes = !isset($this->stickyBucketIdentifierAttributes);


        if (array_key_exists("forcedFeatures", $options)) {
            $this->withForcedFeatures($options['forcedFeatures']);
        }

        if (array_key_exists("features", $options)) {
            $this->withFeatures(($options["features"]));
        }
        if (array_key_exists("attributes", $options)) {
            $this->withAttributes(($options["attributes"]));
        }
    }

    /**
     * @param array<string,mixed> $attributes
     * @return Growthbook
     */
    public function withAttributes(array $attributes): Growthbook
    {
        $this->attributes = $attributes;
        $this->refreshStickyBuckets();
        return $this;
    }

    /**
     * @param callable|null $trackingCallback
     * @return Growthbook
     */
    public function withTrackingCallback($trackingCallback): Growthbook
    {
        $this->trackingCallback = $trackingCallback;
        return $this;
    }

    /**
     * @param array<string,Feature<mixed>|mixed> $features
     * @return Growthbook
     */
    public function withFeatures(array $features): Growthbook
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
     * @return Growthbook
     */
    public function withForcedVariations(array $forcedVariations): Growthbook
    {
        $this->forcedVariations = $forcedVariations;
        return $this;
    }

    /**
     * @param array<string, FeatureResult<mixed>> $forcedFeatures
     * @return Growthbook
     */
    public function withForcedFeatures(array $forcedFeatures)
    {
        $this->forcedFeatures = $forcedFeatures;
        return $this;
    }

    /**
     * @param string $url
     * @return Growthbook
     */
    public function withUrl(string $url): Growthbook
    {
        $this->url = $url;
        return $this;
    }

    public function withLogger(?LoggerInterface $logger = null): Growthbook
    {
        $this->logger = $logger;
        return $this;
    }

    public function setLogger(?LoggerInterface $logger = null): void
    {
        $this->logger = $logger;
    }

    public function withHttpClient(\Psr\Http\Client\ClientInterface $client, \Psr\Http\Message\RequestFactoryInterface $requestFactory): Growthbook
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;
        return $this;
    }

    public function withCache(\Psr\SimpleCache\CacheInterface $cache, ?int $ttl = null): Growthbook
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
    public function getForcedFeatures()
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
     * @param T      $default
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
     * @param array  $stack
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
                    if (self::isFilteredOut($rule->filters)) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of filtering (e.g. namespace)", [
                            "feature" => $key,
                            "filters" => $rule->filters
                        ]);
                        continue;
                    }
                }

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
            if ($exp->condition && !Condition::evalCondition($this->attributes, $exp->condition)) {
                $this->log(LogLevel::DEBUG, "Skip experiment because of targeting conditions", [
                    "experiment" => $exp->key
                ]);
                return new ExperimentResult($exp, $hashAttribute, $hashValue, -1, false, $featureId);
            }

            //8.05 Exclude if parent conditions are not met
            if ($exp->parentConditions) {
                $prereqRes = $this->evalPrereqs($exp->parentConditions, []);
                if (in_array( $prereqRes, ['gate', 'fail'])) {
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
        $this->tracks[$exp->key] = new ViewedExperiment($exp, $result);
        if ($this->trackingCallback) {
            try {
                call_user_func($this->trackingCallback, $exp, $result);
            } catch (\Throwable $e) {
                $this->log(LogLevel::ERROR, "Error calling the trackingCallback function", [
                    "experiment" => $exp->key,
                    "error" => $e
                ]);
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
     * @param mixed  $context
     */
    public function log(string $level, string $message, $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    public static function hash(string $seed, string $value, int $version): ?float
    {
        // New hashing algorithm
        if ($version === 2) {
            $n = hexdec(hash("fnv1a32", hexdec(hash("fnv1a32", $seed . $value)) . ""));
            return ($n % 10000) / 10000;
        } // Original hashing algorithm (with a bias flaw)
        elseif ($version === 1) {
            $n = hexdec(hash("fnv1a32", $value . $seed));
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
     * @param array{seed:string,ranges:array{0:float,1:float}[],hashVersion?:int,attribute?:string}[] $filters
     * @return bool
     */
    private function isFilteredOut(array $filters): bool
    {
        foreach ($filters as $filter) {
            list(, $hashValue) = $this->getHashValue($filter["attribute"] ?? "id");
            if ($hashValue === "") {
                return false;
            }

            $n = self::hash($filter["seed"] ?? "", $hashValue, $filter["hashVersion"] ?? 2);
            if ($n === null) {
                return false;
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
                return (int)$i;
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
        $variation = (int)$params[$id];
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
    public function loadFeatures(string $clientKey, string $apiHost = "", string $decryptionKey = ""): void
    {
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

        // The features URL is also the cache key
        $url = rtrim(empty($this->apiHost) ? self::DEFAULT_API_HOST : $this->apiHost, "/") . "/api/features/" . $this->clientKey;
        $cacheKey = md5($url);

        // First try fetching from cache
        if ($this->cache) {
            $featuresJSON = $this->cache->get($cacheKey);
            if ($featuresJSON) {
                $features = json_decode($featuresJSON, true);
                if ($features && is_array($features)) {
                    $this->log(LogLevel::INFO, "Load features from cache", ["url" => $url, "numFeatures" => count($features)]);
                    $this->withFeatures($features);
                    return;
                }
            }
        }

        // Otherwise, fetch from API
        $req = $this->requestFactory->createRequest('GET', $url);
        $res = $this->httpClient->sendRequest($req);
        $body = $res->getBody();
        $parsed = json_decode($body, true);
        if (!$parsed || !is_array($parsed) || !array_key_exists("features", $parsed)) {
            $this->log(LogLevel::WARNING, "Could not load features", ["url" => $url, "responseBody" => $body]);
            return;
        }

        // Set features and cache for next time
        $features = array_key_exists("encryptedFeatures", $parsed)
            ? json_decode($this->decrypt($parsed["encryptedFeatures"]), true)
            : $parsed["features"];

        $this->log(LogLevel::INFO, "Load features from URL", ["url" => $url, "numFeatures" => count($features)]);
        $this->withFeatures($features);
        if ($this->cache) {
            $this->cache->set($cacheKey, json_encode($features), $this->cacheTTL);
            $this->log(LogLevel::INFO, "Cache features", ["url" => $url, "numFeatures" => count($features), "ttl" => $this->cacheTTL]);
        }
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
