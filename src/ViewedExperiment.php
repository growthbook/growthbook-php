<?php

namespace Growthbook;

class ViewedExperiment {
  /**
   * @var InlineExperiment<mixed>
   */
  public $experiment;
  /**
   * @var ExperimentResult<mixed>
   */
  public $result;

  /**
   * @param InlineExperiment<mixed> $exp
   * @param ExperimentResult<mixed> $res
   */
  public function __construct(InlineExperiment $exp, ExperimentResult $res)
  {
    $this->experiment = $exp;
    $this->result = $res;
  }
}