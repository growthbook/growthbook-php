<?php

namespace Growthbook;

/**
 * @template T
 * @deprecated
 */
class TrackData
{
    /** @var string */
    public $userId;
    /** @var User */
    public $user;
    /** @var Experiment<T> */
    public $experiment;
    /** @var OldExperimentResult<T> */
    public $result;

    /**
     * @param User $user
     * @param Experiment<T> $experiment
     * @param OldExperimentResult<T> $result
     */
    public function __construct(User $user, Experiment $experiment, OldExperimentResult $result)
    {
        $this->user = $user;
        $this->experiment = $experiment;
        $this->result = $result;
        $this->userId = $user->getRandomizationId($experiment->randomizationUnit) ?? "";
    }
}
