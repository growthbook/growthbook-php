<?php
namespace Growthbook;

class LookupResult extends ExperimentResult {
  /**
   * @var string
   */
  public $key = "";

  /**
   * @var mixed
   */
  public $value = null;

  public static function fromExperimentResult(ExperimentResult $result, string $key): LookupResult {
    $obj = new LookupResult($result->experiment, $result->variation);
    $obj->key = $key;
    $obj->value = $obj->getData($key);

    return $obj;
  }
}