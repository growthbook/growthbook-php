<?php

namespace Growthbook;

/**
 * @deprecated
 */
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

    public function __construct(?Config $config = null)
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
        $userId = $data->user->getRandomizationId($data->experiment->randomizationUnit);
        $key = $data->experiment->key . $data->experiment->randomizationUnit . $userId;
        if (array_key_exists($key, $this->experimentViewedHash)) {
            return;
        }
        $this->experimentViewedHash[$key] = true;
        $this->experimentsViewed[] = $data;
    }

    /**
     * @param array<string,string> $ids
     * @param array<string,boolean> $groups
     */
    public function user($ids, array $groups = []): User
    {
        // Old usage: $client->user(string $id, array $attributes = [])
        /** @phpstan-ignore-next-line */
        if (is_string($ids)) {
            trigger_error('That GrowthBookClient::user usage is deprecated. It now accepts a single associative array argument.', E_USER_DEPRECATED);
            $ids = ["id"=>$ids, "anonId"=>$ids,];
        }

        return new User(
            $ids,
            $groups,
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
