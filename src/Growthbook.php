<?php

namespace Growthbook;

class Growthbook
{
  /** @var bool */
  public $enabled = true;
  /** @var \Psr\Log\LoggerInterface */
  public $logger = null;
  /** @var string|null */
  private $url = "";
  /** @var array<string,mixed> */
  private $attributes = [];
  /** @var Feature[] */
  private $features = [];
  /** @var array<string,int> */
  private $forcedVariations = [];
  /** @var bool */
  public $qaMode = false;
  /** @var callable|null */
  private $trackingCallback = null;

  /** @var array<string,ViewedExperiment> */
  private $tracks = [];

  /**
   * @param array{enabled?:bool,logger?:\Psr\Log\LoggerInterface,url?:string,attributes?:array<string,mixed>,features?:array<string,array<string,mixed>>,forcedVariations?:array<string,int>,qaMode?:bool,trackingCallback?:callable} $options
   */
  public function __construct(array $options)
  {
    // Warn if any unknown options are passed
    $knownOptions = ["enabled", "logger", "url", "attributes", "features", "forcedVariations", "qaMode", "trackingCallback"];
    $unknownOptions = array_diff(array_keys($options), $knownOptions);
    if (count($unknownOptions)) {
      trigger_error('Unknown Config options: ' . implode(", ", $unknownOptions), E_USER_NOTICE);
    }

    $this->enabled = $options["enabled"] ?? true;
    $this->logger = $options["logger"] ?? null;
    $this->url = $options["url"] ?? $_SERVER['REQUEST_URI'] ?? "";
    $this->attributes = $options["attributes"] ?? [];
    $this->features = $options["features"] ?? [];
    $this->forcedVariations = $options["forcedVariations"] ?? [];
    $this->qaMode = $options["qaMode"] ?? false;
    $this->trackingCallback = $options["trackingCallback"] ?? null;
  }

  public function getAttributes(): array
  {
    return $this->attributes;
  }
  public function setAttributes(array $attributes)
  {
    $this->attributes = $attributes;
  }
  public function getFeatures(): array
  {
    return $this->features;
  }
  public function setFeatures(array $features)
  {
    $this->features = $features;
  }
  public function getForcedVariations(): array
  {
    return $this->forcedVariations;
  }
  public function setForcedVariations(array $forcedVariations)
  {
    $this->forcedVariations = $forcedVariations;
  }
  public function getUrl(): string
  {
    return $this->url;
  }
  public function setUrl(string $url)
  {
    $this->url = $url;
  }

  /**
   * @return ViewedExperiment[]
   */
  public function getViewedExperiments(): array {
    return array_values($this->tracks);
  }

  public function feature(string $key): FeatureResult
  {
    if (!array_key_exists($key, $this->features)) {
      return new FeatureResult(null, "unknownFeature");
    }
    $feature = $this->features[$key];
    if ($feature->rules) {
      foreach ($feature->rules as $rule) {
        if ($rule->condition) {
          if (!Condition::evalCondition($this->attributes, $rule->condition)) {
            continue;
          }
        }
        if (isset($rule->force)) {
          if (isset($rule->coverage)) {
            $hashValue = $this->attributes[$rule->hashAttribute ?? "id"] ?? "";
            if (!$hashValue) {
              continue;
            }
            $n = Util::hash($hashValue . $feature->key);
            if ($n > $rule->coverage) {
              continue;
            }
          }
          return new FeatureResult($rule->force, "force");
        }
        $exp = $rule->toExperiment($key);
        $result = $this->run($exp);
        if (!$result->inExperiment) {
          continue;
        }
        return new FeatureResult($result->value, "experiment", $exp, $result);
      }
    }
    return new FeatureResult($feature->defaultValue ?? null, "defaultValue");
  }

  public function run(Experiment $exp): ExperimentResult
  {
    // 1. Too few variations
    if (count($exp->variations) < 2) {
      return new ExperimentResult($exp);
    }

    // 2. Growthbook disabled
    if (!$this->enabled) {
      return new ExperimentResult($exp);
    }

    $hashAttribute = $exp->hashAttribute ?? "id";
    $hashValue = $this->attributes[$hashAttribute] ?? "";

    // 3. Forced via querystring
    if ($this->url) {
      $qsOverride = Util::getQueryStringOverride($exp->key, $this->url, count($exp->variations));
      if ($qsOverride !== null) {
        return new ExperimentResult($exp, $hashValue, $qsOverride);
      }
    }

    // 4. Forced via forcedVariations
    if (array_key_exists($exp->key, $this->forcedVariations)) {
      return new ExperimentResult($exp, $hashValue, $this->forcedVariations[$exp->key]);
    }

    // 5. Experiment is not active
    if (!$exp->active) {
      return new ExperimentResult($exp, $hashValue);
    }

    // 6. Hash value is empty
    if (!$hashValue) {
      return new ExperimentResult($exp);
    }

    // 7. Not in namespace
    if ($exp->namespace && !Util::inNamespace($hashValue, $exp->namespace)) {
      return new ExperimentResult($exp, $hashValue);
    }

    // 8. Condition fails
    if ($exp->condition && !Condition::evalCondition($this->attributes, $exp->condition)) {
      return new ExperimentResult($exp, $hashValue);
    }

    // 9. Calculate bucket ranges
    $ranges = Util::getBucketRanges(count($exp->variations), $exp->coverage, $exp->weights ?? []);
    $n = Util::hash($hashValue + $exp->key);
    $assigned = Util::chooseVariation($n, $ranges);

    // 10. Not assigned
    if($assigned === -1) {
      return new ExperimentResult($exp, $hashValue);
    }

    // 11. Forced variation
    if($exp->force !== null) {
      return new ExperimentResult($exp, $hashValue, $exp->force);
    }

    // 12. QA mode
    if($this->qaMode) {
      return new ExperimentResult($exp, $hashValue);
    }

    // 13. Build the result object
    $result = new ExperimentResult($exp, $hashValue, $assigned, true);

    // 14. Fire tracking callback
    $this->tracks[$exp->key] = new ViewedExperiment($exp, $result);
    if($this->trackingCallback) {
      try {
        call_user_func($this->trackingCallback, $exp, $result);
      }
      catch(\Throwable $e) {
        // Do nothing
      }
    }

    // 15. Return the result
    return $result;
  }
}
