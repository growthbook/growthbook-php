<?php

namespace Growthbook;

class Config {
  /** @var bool */
  public $enabled = true;
  /** @var bool */
  public $enableQueryStringOverride = false;
  /** @var null|callable(TrackData) */
  public $onExperimentViewed = null;

  /** 
   * @param array<string,mixed> $options
  */
  public function __construct(array $options) {
    $this->enabled = $options["enabled"] ?? true;
    $this->enableQueryStringOverride = $options["enableQueryStringOverride"] ?? false;
    $this->onExperimentViewed = $options["onExperimentViewed"] ?? null;
  }
}