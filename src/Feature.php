<?php

namespace Growthbook;

class Feature {
  /** @var mixed */
  public $defaultValue = null;
  /** @var FeatureRule[] */
  public $rules = [];

  public function __construct(array $feature)
  {
    if(array_key_exists("defaultValue", $feature)) {
      $this->defaultValue = $feature["defaultValue"];
    }
    if(array_key_exists("rules", $feature)) {
      $rules = $feature["rules"];
      if(is_array($rules)) {
        foreach($rules as $rule) {
          if(is_array($rule)) {
            $this->rules[] = new FeatureRule($rule);
          }
        }
      }
    }
  }
}