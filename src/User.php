<?php

namespace Growthbook;

/**
 * @deprecated
 */
class User
{
    /** @var array<string,string> */
    public $ids = [];
    /** @var array<string,boolean> */
    public $groups = [];
    /** @var Client */
    private $client = null;
    /** @var array<string, string> */
    private $attributeMap = [];

    /**
     * @param array<string,string> $ids
     * @param array<string,boolean> $groups
     * @param Client $client
     */
    public function __construct(array $ids, array $groups, Client $client)
    {
        $this->ids = $ids;
        $this->groups = $groups;
        $this->client = $client;
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

        if (!$this->getRandomizationId($experiment->randomizationUnit)) {
            return false;
        }

        if ($experiment->groups) {
            $allowed = false;
            foreach ($experiment->groups as $group) {
                if (array_key_exists($group, $this->groups) && $this->groups[$group]) {
                    $allowed = true;
                }
            }
            if (!$allowed) {
                return false;
            }
        }

        if ($experiment->url && !Util::urlIsValid($experiment->url, $this->client->config->url ?? "")) {
            return false;
        }

        return true;
    }

    public function getRandomizationId(string $unit = "id"): ?string
    {
        if (array_key_exists($unit, $this->ids)) {
            return $this->ids[$unit];
        }
        return null;
    }

    /**
     * @template T
     * @param Experiment<T> $experiment
     * @return OldExperimentResult<T>
     */
    private function runExperiment(Experiment $experiment, bool $isOverride = false): OldExperimentResult
    {
        // If experiments are disabled globally
        if (!$this->client->config->enabled) {
            return new OldExperimentResult($experiment);
        }

        // If querystring override is enabled
        if ($this->client->config->enableQueryStringOverride) {
            $variation = Util::getQueryStringOverride($experiment->key);
            if ($variation !== null) {
                return new OldExperimentResult($experiment, $variation);
            }
        }

        if (!$this->isIncluded($experiment)) {
            return new OldExperimentResult($experiment);
        }

        // A specific variation is forced, return it without tracking
        if ($experiment->force !== null) {
            return new OldExperimentResult($experiment, $experiment->force);
        }

        $userId = $this->getRandomizationId($experiment->randomizationUnit);
        if (!$userId) {
            return new OldExperimentResult($experiment);
        }

        // Hash unique id and experiment id to randomly choose a variation given weights
        $variation = Util::chooseVariation($userId, $experiment);
        $result = new OldExperimentResult($experiment, $variation);

        $this->trackView($experiment, $result);

        return $result;
    }

    /**
     * @template T
     * @param Experiment<T> $experiment
     * @return OldExperimentResult<T>
     */
    public function experiment(Experiment $experiment): OldExperimentResult
    {
        $override = $this->client->getExperimentOverride($experiment->key);
        if ($override) {
            return $this->runExperiment($experiment->withOverride($override));
        }

        return $this->runExperiment($experiment);
    }

    /**
     * @param Experiment<mixed> $experiment
     * @param OldExperimentResult<mixed> $result
     */
    private function trackView(Experiment $experiment, OldExperimentResult $result): void
    {
        if (!$result->inExperiment) {
            return;
        }

        $this->client->trackExperiment(new TrackData($this, $experiment, $result));
    }
}
