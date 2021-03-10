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
  public $experiments = [];

  public function __construct(Config $config = null)
  {
    $this->config = $config ?? new Config([]);
  }

  public function addExperimentsFromJSON(string $experimentsJSON): void
  {
    $experiments = json_decode($experimentsJSON, true);
    foreach($experiments as $options) {
      $key = $options['key'];
      unset($options['key']);
      $this->experiments[] = new Experiment($key, $options);
    }
  }

  public function getExperimentByKey(string $key): ?Experiment {
    foreach($this->experiments as $experiment) {
      if($experiment->key === $key) {
        return $experiment;
      }
    }
    return null;
  }

  /**
   * @param string $level
   * @param string $message
   * @param mixed $context
   */
  public function log(string $level, string $message, $context = []): void {
    if($this->config->logger) {
      $this->config->logger->log($level, $message, $context);
    }
  }

  public function trackExperiment(TrackData $data): void {
    // @codeCoverageIgnoreStart
    if(!$data->result->experiment) {
      return;
    }
    // @codeCoverageIgnoreEnd

    $key = $data->result->experiment->key . $data->user->id;
    if(array_key_exists($key, $this->experimentViewedHash)) {
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
    if(is_string($params)) {
      $params = [
        "id"=>$params,
        "anonId"=>$params,
      ];
      if(func_num_args() > 1) {
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
  public function getTrackData(): array {
    return $this->experimentsViewed;
  }
}
