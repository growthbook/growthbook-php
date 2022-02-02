<?php

namespace Growthbook;

/**
 * @template T
 */
class Experiment
{
    /** @var string */
    public $key;
    /** @var T[] */
    public $variations;
    /** @var boolean */
    public $active;
    /** @var null|int */
    public $force;
    /** @var null|float[] */
    public $weights;
    /** @var float */
    public $coverage;
    /** @var null|array<string,mixed> */
    public $condition;
    /** @var null|array */
    public $namespace;
    /** @var string */
    public $hashAttribute;
    /** 
     * @deprecated
     * @var string 
     */
    public $url;
    /**
     * @deprecated 
     * @var null|string[] 
     */
    public $groups;

    /**
     * @param float[] $weights
     */
    public function withWeights(array $weights)
    {
        $this->weights = $weights;
        return $this;
    }

    public function withCoverage(float $coverage)
    {
        $this->coverage = $coverage;
        return $this;
    }

    /**
     * @param array<string,mixed> $condition
     */
    public function withCondition(array $condition)
    {
        $this->condition = $condition;
        return $this;
    }

    /**
     * @param string $namespace
     * @param float $start The start of the namespace range (between 0 and 1)
     * @param float $end The end of the namespace range (between 0 and 1)
     */
    public function withNamespace(string $namespace, float $start, float $end)
    {
        $this->namespace = [$namespace, $start, $end];
        return $this;
    }

    public function withHashAttribute(string $hashAttribute)
    {
        $this->hashAttribute = $hashAttribute;
        return $this;
    }

    /**
     * @param string $key
     * @param T[] $variations
     * @param array{status?:"draft"|"running"|"stopped",url?:string,weights?:float[],coverage?:float,randomizationUnit?:string,groups?:string[],force?:int|null,active?:boolean,condition?:array<string,mixed>,namespace?:array,hashAttribute?:string} $options
     */
    public function __construct(string $key, $variations, array $options = [])
    {
        $this->key = $key;

        // Deprecated - pass int for variations instead of an array
        // Turn into simple range (e.g. [0,1,2])
        if (is_numeric($variations)) {
            trigger_error('Experiment $variations should be an array. Passing an integer is deprecated.', E_USER_DEPRECATED);
            $numVariations = $variations;
            $variations = [];
            for ($i = 0; $i < $numVariations; $i++) {
                $variations[] = $i;
            }
        }

        // Deprecated - using `anon` option instead of `hashAttribute`
        if (isset($options['anon'])) {
            //trigger_error('The experiment.anon flag is deprecated. Use hashAttribute instead.', E_USER_DEPRECATED);
            if ($options['anon'] && !isset($options['hashAttribute'])) {
                $options['hashAttribute'] = 'anonId';
            }
            unset($options['anon']);
        }

        // Deprecated - randomizationUnit instead of hashAttribute
        if (isset($options["randomizationUnit"])) {
            if ($options['randomizationUnit'] && !isset($options['hashAttribute'])) {
                $options['hashAttribute'] = $options["randomizationUnit"];
            }
            unset($options['randomizationUnit']);
        }

        // Deprecated - status instead of active
        if (isset($options["status"])) {
            if (!isset($options["active"]) && $options["status"] !== "running") {
                $options["active"] = false;
            }
            unset($options["status"]);
        }

        // Warn if any unknown options are passed
        $knownOptions = ["url", "weights", "active", "coverage", "condition", "namespace", "hashAttribute", "groups", "force"];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error('Unknown Experiment options: ' . implode(", ", $unknownOptions), E_USER_NOTICE);
        }

        if (count($variations) < 2) {
            throw new \InvalidArgumentException("Experiments must have at least 2 variations");
        }
        if (count($variations) > 20) {
            throw new \InvalidArgumentException("Experiments must have at most 20 variations");
        }

        $this->variations = $variations;
        $this->weights = $options['weights'] ?? null;
        $this->active = $options["active"] ?? true;
        $this->coverage = $options["coverage"] ?? 1;
        $this->condition = $options["condition"] ?? null;
        $this->namespace = $options["namespace"] ?? null;
        $this->url = $options['url'] ?? "";
        $this->groups = $options["groups"] ?? null;
        $this->force = $options["force"] ?? null;
        $this->hashAttribute = $options["hashAttribute"] ?? "id";
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
