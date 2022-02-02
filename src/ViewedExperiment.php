<?php

namespace Growthbook;

class ViewedExperiment {
  /**
   * @var Experiment
   */
  public $experiment;
  /**
   * @var ExperimentResult
   */
  public $result;

  public function __construct(Experiment $exp, ExperimentResult $res)
  {
    $this->experiment = $exp;
    $this->result = $res;
  }
}