<?php
namespace Growthbook;

class TrackData {
  /** @var User */
  public $user;
  /** @var ExperimentResult */
  public $result;

  public function __construct(User $user, ExperimentResult $result) {
    $this->user = $user;
    $this->result = $result;
  }
}