<?php

namespace Growthbook;

class Client
{
    /** @var Config */
    public $config;
    /** @var array<string,bool> */
    private $experimentViewedHash = [];
    /** @var TrackData<mixed>[] */
    private $experimentsViewed = [];
    /** @var array<string,ExperimentOverride> */
    private $overrides = [];

    public function __construct(Config $config = null)
    {
        $this->config = $config ?? new Config([]);
    }

    /**
     * @param array<string,ExperimentOverride|array> $overrides
     */
    public function importOverrides(array $overrides): void
    {
        foreach ($overrides as $key=>$override) {
            if (is_array($override)) {
                $override = new ExperimentOverride($override);
            }

            $this->overrides[$key] = $override;
        }
    }

    public function getExperimentOverride(string $key): ?ExperimentOverride
    {
        if (array_key_exists($key, $this->overrides)) {
            return $this->overrides[$key];
        }
        return null;
    }

    public function clearExperimentOverrides(): void
    {
        $this->overrides = [];
    }

    /**
     * @param string $level
     * @param string $message
     * @param mixed $context
     */
    public function log(string $level, string $message, $context = []): void
    {
        if ($this->config->logger) {
            $this->config->logger->log($level, $message, $context);
        }
    }

    /**
     * @param TrackData<mixed> $data
     */
    public function trackExperiment(TrackData $data): void
    {
        $key = $data->experiment->key . $data->user->id;
        if (array_key_exists($key, $this->experimentViewedHash)) {
            return;
        }
        $this->experimentViewedHash[$key] = true;
        $this->experimentsViewed[] = $data;
    }

    /**
     * @param array{id?:string,anonId?:string,attributes?:array<string,mixed>} $params
     */
    public function user($params): User
    {
        // Old usage: $client->user(string $id, array $attributes = [])
        /** @phpstan-ignore-next-line */
        if (is_string($params)) {
            trigger_error('That GrowthBookClient::user usage is deprecated. It now accepts a single associative array argument.', E_USER_DEPRECATED);
            $params = ["id"=>$params, "anonId"=>$params,];
            if (func_num_args() > 1) {
                $params["attributes"] = func_get_arg(1);
            }
        }

        // Warn if any unknown options are passed
        $knownOptions = ["id","anonId","attributes"];
        $unknownOptions = array_diff(array_keys($params), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error('Unknown Client->user params: '.implode(", ", $unknownOptions), E_USER_NOTICE);
        }

        return new User(
            $params["anonId"]??"",
            $params["id"]??"",
            $params["attributes"]??[],
            $this
        );
    }

    /**
     * @return TrackData<mixed>[]
     */
    public function getTrackData(): array
    {
        return $this->experimentsViewed;
    }
}
