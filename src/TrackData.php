<?php

namespace Growthbook;

class TrackData
{
    /** @var User */
    public $user;
    /** @var Experiment */
    public $experiment;
    /** @var ExperimentResult */
    public $result;

    public function __construct(User $user, Experiment $experiment, ExperimentResult $result)
    {
        $this->user = $user;
        $this->experiment = $experiment;
        $this->result = $result;
    }
}
