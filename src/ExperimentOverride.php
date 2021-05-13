<?php

namespace Growthbook;

class ExperimentOverride
{
    /** @var null|"draft"|"running"|"stopped" */
    public $status;
    /** @var null|int */
    public $force;
    /** @var null|float[] */
    public $weights;
    /** @var null|float */
    public $coverage;
    /** @var null|string */
    public $url;
    /** @var null|string[] */
    public $groups;

    /**
     * @param array{status?:"draft"|"running"|"stopped",url?:string,weights?:float[],coverage?:float,groups?:string[],force?:int} $options
     */
    public function __construct(array $options = [])
    {
        // Warn if any unknown options are passed
        $knownOptions = ["status","url","weights","coverage","groups","force"];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error('Unknown ExperimentOverride options: '.implode(", ", $unknownOptions), E_USER_NOTICE);
        }

        $this->status = $options['status'] ?? null;
        $this->weights = $options['weights'] ?? null;
        $this->coverage = $options["coverage"] ?? null;
        $this->url = $options['url'] ?? null;
        $this->groups = $options["groups"] ?? null;
        $this->force = $options["force"] ?? null;
    }
}
