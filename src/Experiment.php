<?php

namespace Growthbook;

class Experiment {
  /** @var string */
  public $key;
  /** @var "draft"|"running"|"stopped" */
  public $status;
  /** @var int|(array<array{key?:string,weight?:float,data?:array<string,mixed>}>) */
  public $variations;
  /** @var null|int */
  public $force;
  /** @var float */
  public $coverage;
  /** @var bool */
  public $anon;
  /** @var string */
  public $url;
  /** @var string[] */
  public $targeting;

  /**
   * @param string $key
   * @param array{status?:"draft"|"running"|"stopped",variations?:(int|array<array{key?:string,weight?:float,data?:array<string,mixed>}>), coverage?: float, anon?: bool, targeting?: string[]} $options
   */
  public function __construct(string $key, array $options = [])
  {
    $this->key = $key;
    $this->status = $options['status'] ?? 'running';
    $this->variations = $options['variations'] ?? 2;
    $this->coverage = $options["coverage"] ?? 1;
    $this->anon = $options["anon"] ?? false;
    $this->url = $options['url'] ?? "";
    $this->targeting = $options["targeting"] ?? [];
    $this->force = $options["force"] ?? null;
  }

  /**
   * @return float[]
   */
  private function getEqualWeights(int $numVariations): array
  {
    $weights = [];
    for ($i = 0; $i < $numVariations; $i++) {
      $weights[] = 1 / $numVariations;
    }
    return $weights;
  }

  /**
   * @return float[]
   */
  public function getScaledWeights(): array
  {
    $coverage = $this->coverage;
    if($coverage < 0 || $coverage > 1) $coverage = 1;

    $weights = [];
    if(is_array($this->variations)) {
      $weights = array_map(function ($v) { return $v['weight'] ?? 0; }, $this->variations);
      $totalWeight = 0;
      foreach($weights as $w) {
        $totalWeight += $w;
      }
      if($totalWeight < 0.99 || $totalWeight > 1.01) {
        $weights = $this->getEqualWeights(count($weights));
      }
    }
    else {
      $weights = $this->getEqualWeights($this->variations);
    }

    if(count($weights) < 2 || count($weights) > 20) {
      $weights = [0.5, 0.5];
    }

    // Scale weights by traffic coverage
    return array_map(function ($n) use ($coverage) {
      return $n * $coverage;
    }, $weights);
  }
}