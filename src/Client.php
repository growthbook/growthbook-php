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
  /** @var Experiment[] */
  private $experiments = [];

  public function __construct(Config $config = null)
  {
    $this->config = $config ?? new Config([]);
  }

  /**
   * @param Experiment[] $experiments
   */
  public function setExperimentConfigs(array $experiments): void
  {
    $this->experiments = $experiments;
  }

  /**
   * @return Experiment[]
   */
  public function getAllExperimentConfigs(): array {
    return $this->experiments;
  }

  public function getExperimentConfigs(string $id): ?Experiment {
    foreach($this->experiments as $experiment) {
      if($experiment->id === $id) {
        return $experiment;
      }
    }
    return null;
  }

  public function trackExperiment(TrackData $data): void {
    if(!$data->result->experiment) {
      return;
    }

    $key = $data->result->experiment->id . $data->user->id;
    if(array_key_exists($key, $this->experimentViewedHash)) {
      return;
    }
    $this->experimentViewedHash[$key] = true;

    $this->experimentsViewed[] = $data;
    $callback = $this->config->onExperimentViewed;
    if($callback) {
      $callback($data);
    }
  }

  /**
   * @param string $id
   * @param array<string, mixed> $attributes
   */
  public function user(string $id, array $attributes = []): User
  {
    return new User($id, $attributes, $this);
  }

  /**
   * @param string $apiKey
   * @param array<string, mixed> $guzzleSettings
   * @return Experiment[]
   */
  public static function fetchExperimentConfigs(string $apiKey, array $guzzleSettings = []): array
  {
    $client = new \GuzzleHttp\Client();
    $res = $client->request('GET', "https://cdn.growthbook.io/config/$apiKey", $guzzleSettings);

    $body = $res->getBody();
    $json = json_decode($body);

    if ($res->getStatusCode() !== 200) {
      throw new \Exception(isset($json['message']) ? $json['message'] : "There was an error fetching experiment configs");
    }

    if ($json && is_array($json) && isset($json['experiments']) && is_array($json['experiments'])) {
      $experiments = [];
      foreach($json["experiments"] as $id=>$experiment) {
        $experiments[] = new Experiment($id, $experiment["variation"], $experiment);
      }
      return $experiments;
    }

    throw new \Exception("Failed to parse experiment configs");
  }
}
