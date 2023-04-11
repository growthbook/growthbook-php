<?php

namespace Growthbook;

class Growthbook
{
    private const DEFAULT_API_HOST = "https://cdn.growthbook.io";
    private const CACHE_KEY = "growthbook_features_v1";

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

    public static function create(): Growthbook
    {
        return new Growthbook();
    }

    /**
     * @param array{enabled?:bool,logger?:\Psr\Log\LoggerInterface,url?:string,attributes?:array<string,mixed>,features?:array<string,mixed>,forcedVariations?:array<string,int>,qaMode?:bool,trackingCallback?:callable,cache?:\Psr\SimpleCache\CacheInterface,httpClient?:\Psr\Http\Client\ClientInterface,httpRequestFactory?:\Psr\Http\Message\RequestFactoryInterface,clientKey?:string,apiHost?:string,decryptionKey?:string} $options
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
            "qaMode",
            "trackingCallback",
            "cache",
            "httpClient",
            "httpRequestFactory",
            "clientKey",
            "apiHost",
            "decryptionKey",
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

        $this->cache = $options["cache"] ?? null;
        $this->httpClient = $options["httpClient"] ?? null;
        $this->requestFactory = $options["httpRequestFactory"] ?? null;

        if (array_key_exists("clientKey", $options)) {
            $this->withApi(
                $options["clientKey"],
                $options["apiHost"] ?? self::DEFAULT_API_HOST,
                $options["decryptionKey"] ?? ""
            );
        }
        if (array_key_exists("features", $options)) {
            $this->withFeatures(($options["features"]));
        }
        if (array_key_exists("attributes", $options)) {
            $this->withAttributes(($options["attributes"]));
        }
    }

    public function withApi(string $clientKey, string $apiHost = self::DEFAULT_API_HOST, string $decryptionKey = ""): Growthbook
    {
        $this->clientKey = $clientKey;
        $this->apiHost = $apiHost;
        $this->decryptionKey = $decryptionKey;
        return $this;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return Growthbook
     */
    public function withAttributes(array $attributes): Growthbook
    {
        $this->attributes = $attributes;
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
     * @param string $url
     * @return Growthbook
     */
    public function withUrl(string $url): Growthbook
    {
        $this->url = $url;
        return $this;
    }
    public function withLogger(\Psr\Log\LoggerInterface $logger = null): Growthbook
    {
        $this->logger = $logger;
        return $this;
    }

    public function withHttpClient(\Psr\Http\Client\ClientInterface $client, \Psr\Http\Message\RequestFactoryInterface $requestFactory): Growthbook
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;
        return $this;
    }

    public function withCache(\Psr\SimpleCache\CacheInterface $cache, int $ttl = null): Growthbook
    {
        $this->cache = $cache;
        if ($ttl !== null) {
            $this->cacheTTL = $ttl;
        }
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
     * @template T
     * @param string $key
     * @return FeatureResult<T>|FeatureResult<null>
     */
    public function getFeature(string $key): FeatureResult
    {
        if (!array_key_exists($key, $this->features)) {
            return new FeatureResult(null, "unknownFeature");
        }
        $feature = $this->features[$key];
        if ($feature->rules) {
            foreach ($feature->rules as $rule) {
                if ($rule->condition) {
                    if (!Condition::evalCondition($this->attributes, $rule->condition)) {
                        continue;
                    }
                }
                if (isset($rule->force)) {
                    if (isset($rule->coverage)) {
                        $hashValue = $this->attributes[$rule->hashAttribute ?? "id"] ?? "";
                        if (!$hashValue) {
                            continue;
                        }
                        $n = static::hash($hashValue . $key);
                        if ($n > $rule->coverage) {
                            continue;
                        }
                    }
                    return new FeatureResult($rule->force, "force");
                }
                $exp = $rule->toExperiment($key);
                if (!$exp) {
                    continue;
                }
                $result = $this->runExperiment($exp, $key);
                if (!$result->inExperiment) {
                    continue;
                }
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
    private function runExperiment(InlineExperiment $exp, string $featureId = null): ExperimentResult
    {
        // 1. Too few variations
        if (count($exp->variations) < 2) {
            return new ExperimentResult($exp, "", -1, false, $featureId);
        }

        // 2. Growthbook disabled
        if (!$this->enabled) {
            return new ExperimentResult($exp, "", -1, false, $featureId);
        }

        $hashAttribute = $exp->hashAttribute ?? "id";
        $hashValue = strval($this->attributes[$hashAttribute] ?? "");

        // 3. Forced via querystring
        if ($this->url) {
            $qsOverride = static::getQueryStringOverride($exp->key, $this->url, count($exp->variations));
            if ($qsOverride !== null) {
                return new ExperimentResult($exp, $hashValue, $qsOverride, false, $featureId);
            }
        }

        // 4. Forced via forcedVariations
        if (array_key_exists($exp->key, $this->forcedVariations)) {
            return new ExperimentResult($exp, $hashValue, $this->forcedVariations[$exp->key], false, $featureId);
        }

        // 5. Experiment is not active
        if (!$exp->active) {
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 6. Hash value is empty
        if (!$hashValue) {
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 7. Not in namespace
        if ($exp->namespace && !static::inNamespace($hashValue, $exp->namespace)) {
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 8. Condition fails
        if ($exp->condition && !Condition::evalCondition($this->attributes, $exp->condition)) {
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 9. Calculate bucket ranges
        $ranges = static::getBucketRanges(count($exp->variations), $exp->coverage, $exp->weights ?? []);
        $n = static::hash($hashValue . $exp->key);
        $assigned = static::chooseVariation($n, $ranges);

        // 10. Not assigned
        if ($assigned === -1) {
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 11. Forced variation
        if ($exp->force !== null) {
            return new ExperimentResult($exp, $hashValue, $exp->force, false, $featureId);
        }

        // 12. QA mode
        if ($this->qaMode) {
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 13. Build the result object
        $result = new ExperimentResult($exp, $hashValue, $assigned, true, $featureId);

        // 14. Fire tracking callback
        $this->tracks[$exp->key] = new ViewedExperiment($exp, $result);
        if ($this->trackingCallback) {
            try {
                call_user_func($this->trackingCallback, $exp, $result);
            } catch (\Throwable $e) {
                // Do nothing
            }
        }

        // 15. Return the result
        return $result;
    }


    /**
     * @param string $str
     * @return float
     */
    public static function hash(string $str): float
    {
        $n = hexdec(hash("fnv1a32", $str));
        return ($n % 1000) / 1000;
    }

    /**
     * @param string $userId
     * @param array{0:string,1:float,2:float} $namespace
     * @return bool
     */
    public static function inNamespace(string $userId, array $namespace): bool
    {
        // @phpstan-ignore-next-line
        if (count($namespace) < 3) {
            return false;
        }
        $n = static::hash($userId . "__" . $namespace[0]);
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
     * @param int $numVariations
     * @param float $coverage
     * @param null|(float[]) $weights
     * @return array{0:float,1:float}[]
     */
    public static function getBucketRanges(int $numVariations, float $coverage, array $weights = null): array
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
     * @param float $n
     * @param array{0:float,1:float}[] $ranges
     * @return int
     */
    public static function chooseVariation(float $n, array $ranges): int
    {
        foreach ($ranges as $i => $range) {
            if ($n >= $range[0] && $n < $range[1]) {
                return (int) $i;
            }
        }
        return -1;
    }

    /**
     * @param string $id
     * @param string $url
     * @param int $numVariations
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

    public function loadFeatures(): void
    {
        if (!$this->clientKey) {
            throw new \Exception("Must specify a clientKey before loading features.");
        }
        if (!$this->httpClient) {
            throw new \Exception("Must set an HTTP Client before loading features.");
        }
        if (!$this->requestFactory) {
            throw new \Exception("Must set an HTTP Request Factory before loading features");
        }

        if ($this->cache) {
            try {
                $featuresJSON = $this->cache->get(self::CACHE_KEY);
                if ($featuresJSON) {
                    $features = json_decode($featuresJSON, true);
                    if ($features && is_array($features)) {
                        $this->withFeatures($features);
                        return;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore cache exceptions
            }
        }

        try {
            $url = rtrim($this->apiHost ?? self::DEFAULT_API_HOST, "/") . "/api/features/" . $this->clientKey;
            $req = $this->requestFactory->createRequest('GET', $url);
            $res = $this->httpClient->sendRequest($req);
            $body = $res->getBody();
            $parsed = json_decode($body, true);
            if (!$parsed || !is_array($parsed) || !array_key_exists("features", $parsed)) {
                return;
            }

            $features = $parsed["features"];
            $this->withFeatures($features);
            if ($this->cache) {
                $this->cache->set(self::CACHE_KEY, json_encode($features), $this->cacheTTL);
            }
        } catch (\Throwable $e) {
            // Ignore fetch/cache exceptions
        }
    }
}
