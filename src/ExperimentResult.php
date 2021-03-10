<?php
namespace Growthbook;

class ExperimentResult {
  /** @var null|Experiment */
  public $experiment;
  /** @var int */
  public $variation;
  /** @var string */
  public $variationKey;

  public function __construct(Experiment $experiment = null, int $variation = -1) {
    $this->experiment = $experiment;
    $this->variation = $variation;
    $this->variationKey = "";

    if($experiment && $variation >= 0 && is_array($experiment->variations)) {
      $this->variationKey = $experiment->variations[$variation]["key"] ?? ($variation."");
    }
  }

  /** @return mixed */
  public function getData(string $key) {
    if(!$this->experiment) {
      return null;
    }
    if(!is_array($this->experiment->variations)) {
      return null;
    }

    $var = $this->experiment->variations[0];
    if($this->variation > 0) {
      $var = $this->experiment->variations[$this->variation];
    }
    if(!$var) return null;

    if(!array_key_exists("data", $var)) return null;

    return $var["data"][$key] ?? null;
  }
}