<?php

namespace Growthbook;

class Experiment {
  /** @var string */
  public $id;
  /** @var int */
  public $variations;
  /** @var float */
  public $coverage;
  /** @var float[] */
  public $weights;
  /** @var string[] */
  public $targeting;
  /** @var array<string,array<mixed>> */
  public $data;

  /**
   * @param string $id
   * @param int $variations
   * @param array{coverage?: float, weights?: array<float>, targeting?: array<string>, data?: array<string,array<mixed>>} $options
   */
  public function __construct(string $id, int $variations, array $options = [])
  {
    $this->id = $id;
    $this->variations = $variations;
    $this->coverage = $options["coverage"] ?? 1;
    $this->weights = $options["weights"] ?? $this->getEqualWeights();
    $this->targeting = $options["targeting"] ?? [];
    $this->data = $options["data"] ?? [];
  }

  /**
   * @return float[]
   */
  private function getEqualWeights(): array
  {
    $weights = [];
    for ($i = 0; $i < $this->variations; $i++) {
      $weights[] = 1 / $this->variations;
    }
    return $weights;
  }

  /**
   * @return float[]
   */
  public function getScaledWeights(): array
  {
    $coverage = $this->coverage;

    // Scale weights by traffic coverage
    return array_map(function ($n) use ($coverage) {
      return $n * $coverage;
    }, $this->weights);
  }
}