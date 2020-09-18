<?php
namespace Growthbook;

class ExperimentResult {
  /** @var null|Experiment */
  public $experiment;
  /** @var int */
  public $variation;

  public function __construct(Experiment $experiment = null, int $variation = -1) {
    $this->experiment = $experiment;
    $this->variation = $variation;
  }

  /** @return mixed */
  public function getData(string $key) {
    if(!$this->experiment) {
      return null;
    }
    if(!array_key_exists($key, $this->experiment->data)) {
      return null;
    }

    $data = $this->experiment->data[$key];
    // Fallback to control value
    if(!array_key_exists($this->variation, $data)) {
      return $data[0];
    }

    return $data[$this->variation];
  }
}