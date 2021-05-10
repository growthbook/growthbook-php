<?php

namespace Growthbook;

class Experiment
{
    /** @var string */
    public $key;
    /** @var "draft"|"running"|"stopped" */
    public $status;
    /** @var array<int,mixed> */
    public $variations;
    /** @var null|int */
    public $force;
    /** @var float[] */
    public $weights;
    /** @var float */
    public $coverage;
    /** @var bool */
    public $anon;
    /** @var string */
    public $url;
    /** @var string[] */
    public $targeting;

    /**
     * @param string $key
     * @param int|array<int,mixed> $variations
     * @param array{status?:"draft"|"running"|"stopped", url?: string, weights?: float[], coverage?: float, anon?: bool, targeting?: string[]} $options
     */
    public function __construct(string $key, $variations = [0,1], array $options = [])
    {
        $this->key = $key;

        // Deprecated - pass int for variations instead of an array
        // Turn into simple range arrage (e.g. [0,1,2])
        if (is_numeric($variations)) {
            $numVariations = $variations;
            $variations = [];
            for ($i=0; $i<$numVariations; $i++) {
                $variations[] = $i;
            }
        }

        if (count($variations) < 2) {
            throw new \InvalidArgumentException("Experiments must have at least 2 variations");
        }
        if (count($variations) > 20) {
            throw new \InvalidArgumentException("Experiments must have at most 20 variations");
        }

        $this->status = $options['status'] ?? 'running';
        $this->variations = $variations;
        $this->weights = $options['weights'] ?? $this->getEqualWeights(count($this->variations));
        $this->coverage = $options["coverage"] ?? 1;
        $this->anon = $options["anon"] ?? false;
        $this->url = $options['url'] ?? "";
        $this->targeting = $options["targeting"] ?? [];
        $this->force = $options["force"] ?? null;
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

    public function withOverride(ExperimentOverride $override): Experiment
    {
        return new Experiment($this->key, $this->variations, [
      "anon" => $this->anon,
      "status" => $override->status !== null ? $override->status : $this->status,
      "weights" => $override->weights !== null ? $override->weights : $this->weights,
      "coverage" => $override->coverage !== null ? $override->coverage : $this->coverage,
      "url" => $override->url !== null ? $override->url : $this->url,
      "targeting" => $override->targeting !== null ? $override->targeting : $this->targeting,
      "force" => $override->force !== null ? $override->force : $this->force,
    ]);
    }
}
