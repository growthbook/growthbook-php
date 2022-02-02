<?php

namespace Growthbook;

class FeatureRule {
  /** @var null|array<string,mixed> */
  public $condition;
  /** @var null|float */
  public $coverage;
  /** @var null|mixed */
  public $force;
  /** @var null|mixed[] */
  public $variations;
  /** @var null|string */
  public $trackingKey;
  /** @var null|float[] */
  public $weights;
  /** @var null|array */
  public $namespace;
  /** @var null|string */
  public $hashAttribute;

  public function __construct(array $rule)
  {
    if(array_key_exists("condition", $rule)) {
      $this->condition = $rule["condition"];
    }
    if(array_key_exists("coverage", $rule)) {
      $this->coverage = $rule["coverage"];
    }
    if(array_key_exists("force", $rule)) {
      $this->force = $rule["force"];
    }
    if(array_key_exists("variations", $rule)) {
      $this->variations = $rule["variations"];
    }
    if(array_key_exists("trackingKey", $rule)) {
      $this->trackingKey = $rule["trackingKey"];
    }
    if(array_key_exists("weights", $rule)) {
      $this->weights = $rule["weights"];
    }
    if(array_key_exists("namespace", $rule)) {
      $this->namespace = $rule["namespace"];
    }
    if(array_key_exists("hashAttribute", $rule)) {
      $this->hashAttribute = $rule["hashAttribute"];
    }
  }

  public function toExperiment(string $featureKey): ?Experiment {
    if(!isset($this->variations)) return null;

    $exp = new Experiment($this->trackingKey ?? $featureKey, $this->variations);

    if(isset($this->coverage)) {
      $exp->coverage = $this->coverage;
    }
    if(isset($this->weights)) {
      $exp->weights = $this->weights;
    }
    if(isset($this->hashAttribute)) {
      $exp->hashAttribute = $this->hashAttribute;
    }
    if(isset($this->namespace)) {
      $exp->namespace = $this->namespace;
    }

    return $exp;
  }
}