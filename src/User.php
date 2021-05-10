<?php

namespace Growthbook;

class User
{
    /** @var string */
    public $id = "";
    /** @var string */
    public $anonId = "";
    /** @var array<string, mixed> */
    private $attributes = [];
    /** @var Client */
    private $client = null;
    /** @var array<string, string> */
    private $attributeMap = [];

    /**
     * @param string $anonId
     * @param string $id
     * @param array<string,mixed> $attributes
     * @param Client $client
     */
    public function __construct(string $anonId, string $id, array $attributes, Client $client)
    {
        $this->anonId = $anonId;
        $this->id = $id;
        $this->attributes = $attributes;
        $this->client = $client;
        $this->updateAttributeMap();
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function setAttributes(array $attributes, bool $merge = false): void
    {
        if ($merge) {
            $this->attributes = array_merge($this->attributes, $attributes);
        } else {
            $this->attributes = $attributes;
        }

        $this->updateAttributeMap();
    }

    /**
     * @return array<string,mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param Experiment<mixed> $experiment
     * @return bool
     */
    private function isIncluded(Experiment $experiment): bool
    {
        $numVariations = count($experiment->variations);
        if ($numVariations < 2) {
            return false;
        }

        if ($experiment->status === "draft") {
            return false;
        }

        if ($experiment->status === "stopped" && $experiment->force === null) {
            return false;
        }

        $userId = $experiment->anon ? $this->anonId : $this->id;
        if (!$userId) {
            return false;
        }

        if ($experiment->targeting && !$this->isTargeted($experiment->targeting)) {
            return false;
        }

        if ($experiment->url && !Util::urlIsValid($experiment->url)) {
            return false;
        }

        return true;
    }

    /**
     * @template T
     * @param Experiment<T> $experiment
     * @return ExperimentResult<T>
     */
    private function runExperiment(Experiment $experiment, bool $isOverride = false): ExperimentResult
    {
        // If experiments are disabled globally
        if (!$this->client->config->enabled) {
            return new ExperimentResult($experiment);
        }

        // If querystring override is enabled
        if ($this->client->config->enableQueryStringOverride) {
            $variation = Util::getQueryStringOverride($experiment->key);
            if ($variation !== null) {
                return new ExperimentResult($experiment, $variation);
            }
        }

        if (!$this->isIncluded($experiment)) {
            return new ExperimentResult($experiment);
        }

        // A specific variation is forced, return it without tracking
        if ($experiment->force !== null) {
            return new ExperimentResult($experiment, $experiment->force);
        }

        $userId = $experiment->anon ? $this->anonId : $this->id;

        // Hash unique id and experiment id to randomly choose a variation given weights
        $variation = Util::chooseVariation($userId, $experiment);
        $result = new ExperimentResult($experiment, $variation);

        $this->trackView($experiment, $result);

        return $result;
    }

    /**
     * @template T
     * @param Experiment<T> $experiment
     * @return ExperimentResult<T>
     */
    public function experiment(Experiment $experiment): ExperimentResult
    {
        $override = $this->client->getExperimentOverride($experiment->key);
        if ($override) {
            return $this->runExperiment($experiment->withOverride($override));
        }

        return $this->runExperiment($experiment);
    }

    /**
     * @param Experiment<mixed> $experiment
     * @param ExperimentResult<mixed> $result
     */
    private function trackView(Experiment $experiment, ExperimentResult $result): void
    {
        if (!$result->inExperiment) {
            return;
        }

        $this->client->trackExperiment(new TrackData($this, $experiment, $result));
    }

    /**
     * @param string $prefix
     * @param mixed $val
     * @return array<array{k:string,v:string}>
     */
    private function flattenUserValues(string $prefix = "", $val=""): array
    {
        // Associative array
        if (is_array($val) && array_keys($val) !== range(0, count($val) - 1)) {
            $ret = [];
            foreach ($val as $k => $v) {
                $ret = array_merge(
                    $ret,
                    $this->flattenUserValues($prefix ? $prefix . "." . $k : $k, $v)
                );
            }
            return $ret;
        }

        // Numeric array
        if (is_array($val)) {
            $val = implode(",", $val);
        } elseif (is_bool($val)) {
            $val = $val ? "true" : "false";
        }

        return [
      [
        "k" => $prefix,
        "v" => (string) $val
      ]
    ];
    }

    private function updateAttributeMap(): void
    {
        $this->attributeMap = [];
        $flat = $this->flattenUserValues("", $this->attributes);
        foreach ($flat as $item) {
            $this->attributeMap[$item["k"]] = $item["v"];
        }
    }

    /**
     * @param string[] $rules
     */
    private function isTargeted(array $rules): bool
    {
        foreach ($rules as $rule) {
            $parts = explode(" ", $rule, 3);
            if (count($parts) !== 3) {
                continue;
            }

            $key = trim($parts[0]);
            $actual = $this->attributeMap[$key] ?? "";
            if (!Util::checkRule($actual, trim($parts[1]), trim($parts[2]), $this->client)) {
                return false;
            }
        }

        return true;
    }
}
