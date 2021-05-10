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
    public $targeting;

    /**
     * @param array{status?:"draft"|"running"|"stopped", url?: string, weights?: float[], coverage?: float, targeting?: string[]} $options
     */
    public function __construct(array $options = [])
    {
        $this->status = $options['status'] ?? null;
        $this->weights = $options['weights'] ?? null;
        $this->coverage = $options["coverage"] ?? null;
        $this->url = $options['url'] ?? null;
        $this->targeting = $options["targeting"] ?? null;
        $this->force = $options["force"] ?? null;
    }
}
