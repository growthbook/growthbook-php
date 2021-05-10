<?php

namespace Growthbook;

/**
 * @template T
 */
class TrackData
{
    /** @var User */
    public $user;
    /** @var Experiment<T> */
    public $experiment;
    /** @var ExperimentResult<T> */
    public $result;

    /**
     * @param User $user
     * @param Experiment<T> $experiment
     * @param ExperimentResult<T> $result
     */
    public function __construct(User $user, Experiment $experiment, ExperimentResult $result)
    {
        $this->user = $user;
        $this->experiment = $experiment;
        $this->result = $result;
    }
}
