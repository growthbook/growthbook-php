<?php

namespace Growthbook;

class Client
{
    /** @var Config */
    public $config;
    /** @var array<string,bool> */
    private $experimentViewedHash = [];
    /** @var TrackData[] */
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

    public function trackExperiment(TrackData $data): void
    {
        // @codeCoverageIgnoreStart
        if (!$data->result->experiment) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $key = $data->result->experiment->key . $data->user->id;
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
            $params = [
        "id"=>$params,
        "anonId"=>$params,
      ];
            if (func_num_args() > 1) {
                $params["attributes"] = func_get_arg(1);
            }
        }

        return new User(
            $params["anonId"]??"",
            $params["id"]??"",
            $params["attributes"]??[],
            $this
        );
    }

    /**
     * @return TrackData[]
     */
    public function getTrackData(): array
    {
        return $this->experimentsViewed;
    }
}
