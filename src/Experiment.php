<?php

namespace Growthbook;

/**
 * @deprecated
 * @template T
 */
class Experiment
{
    /** @var string */
    public $key;
    /** @var "draft"|"running"|"stopped" */
    public $status;
    /** @var T[] */
    public $variations;
    /** @var null|int */
    public $force;
    /** @var float[] */
    public $weights;
    /** @var float */
    public $coverage;
    /** @var string */
    public $randomizationUnit;
    /** @var string */
    public $url;
    /** @var string[] */
    public $groups;

    /**
     * @param string $key
     * @param int|T[] $variations
     * @param array{status?:"draft"|"running"|"stopped",url?:string,weights?:float[],coverage?:float,randomizationUnit?:string,groups?:string[],force?:int|null} $options
     */
    public function __construct(string $key, $variations = [0,1], array $options = [])
    {
        $this->key = $key;

        // Deprecated - pass int for variations instead of an array
        // Turn into simple range (e.g. [0,1,2])
        if (is_numeric($variations)) {
            trigger_error('Experiment $variations should be an array. Passing an integer is deprecated.', E_USER_DEPRECATED);
            $numVariations = $variations;
            $variations = [];
            for ($i=0; $i<$numVariations; $i++) {
                $variations[] = $i;
            }
        }

        // Deprecated - using `anon` option instead of `randomizationUnit`
        if (isset($options['anon'])) {
            //trigger_error('The experiment.anon flag is deprecated. Use randomizationUnit instead.', E_USER_DEPRECATED);
            if ($options['anon'] && !isset($options['randomizationUnit'])) {
                $options['randomizationUnit'] = 'anonId';
            }
            unset($options['anon']);
        }

        // Warn if any unknown options are passed
        $knownOptions = ["status","url","weights","coverage","randomizationUnit","groups","force"];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error('Unknown Experiment options: '.implode(", ", $unknownOptions), E_USER_NOTICE);
        }

        if (count($variations) < 2) {
            throw new \InvalidArgumentException("Experiments must have at least 2 variations");
        }
        if (count($variations) > 20) {
            throw new \InvalidArgumentException("Experiments must have at most 20 variations");
        }

        $this->status = $options['status'] ?? 'running';
        /** @phpstan-ignore-next-line */
        $this->variations = $variations;
        $this->weights = $options['weights'] ?? $this->getEqualWeights(count($this->variations));
        $this->coverage = $options["coverage"] ?? 1;
        $this->url = $options['url'] ?? "";
        $this->groups = $options["groups"] ?? [];
        $this->force = $options["force"] ?? null;
        $this->randomizationUnit = $options["randomizationUnit"] ?? "id";
    }

    /**
     * @return float[]
     */
    private function getEqualWeights(int $numVariations): array
    {
        $weights = [];
        for ($i = 0; $i < $numVariations; $i++) {
            $weights[] = 1 / $numVariations;
        }
        return $weights;
    }

    /**
     * @return float[]
     */
    public function getScaledWeights(): array
    {
        $coverage = $this->coverage;
        if ($coverage < 0 || $coverage > 1) {
            $coverage = 1;
        }

        $weights = $this->weights;
        if (count($weights) !== count($this->variations)) {
            $weights = $this->getEqualWeights(count($this->variations));
        } else {
            $sum = 0;
            foreach ($weights as $weight) {
                $sum += $weight;
            }
            if ($sum < 0.98 || $sum > 1.02) {
                $weights = $this->getEqualWeights(count($this->variations));
            }
        }

        // Scale weights by traffic coverage
        return array_map(function ($n) use ($coverage) {
            return $n * $coverage;
        }, $weights);
    }

    /**
     * @param ExperimentOverride $override
     * @return Experiment<T>
     */
    public function withOverride(ExperimentOverride $override): Experiment
    {
        return new Experiment($this->key, $this->variations, [
            "randomizationUnit" => $this->randomizationUnit,
            "status" => $override->status !== null ? $override->status : $this->status,
            "weights" => $override->weights !== null ? $override->weights : $this->weights,
            "coverage" => $override->coverage !== null ? $override->coverage : $this->coverage,
            "url" => $override->url !== null ? $override->url : $this->url,
            "groups" => $override->groups !== null ? $override->groups : $this->groups,
            "force" => $override->force !== null ? $override->force : $this->force,
        ]);
    }
}
