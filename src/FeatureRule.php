<?php

namespace Growthbook;

/**
 * @template T
 */
class FeatureRule {
  /** @var null|array<string,mixed> */
  public $condition;
  /** @var null|float */
  public $coverage;
  /** @var null|T */
  public $force;
  /** @var null|T[] */
  public $variations;
  /** @var null|string */
  public $key;
  /** @var null|float[] */
  public $weights;
  /** @var null|array{0:string,1:float,2:float} */
  public $namespace;
  /** @var null|string */
  public $hashAttribute;

  /**
   * @param array{condition:?array<string,mixed>,coverage:?float,force:?T,variations:?T[],key:?string,weights:?float[],namespace:?array{0:string,1:float,2:float},hashAttribute:?string} $rule
   */
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
    if(array_key_exists("key", $rule)) {
      $this->key = $rule["key"];
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

  /**
   * @param string $featureKey
   * @return null|InlineExperiment<T>
   */
  public function toExperiment(string $featureKey): ?InlineExperiment {
    if(!isset($this->variations)) return null;

    $exp = new InlineExperiment($this->key ?? $featureKey, $this->variations);

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