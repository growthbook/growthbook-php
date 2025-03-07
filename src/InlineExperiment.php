<?php

namespace Growthbook;

/**
 * @template T
 */
class InlineExperiment
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
    /** @var null|array{0:string,1:float,2:float} */
    public $namespace;
    /** @var string */
    public $hashAttribute;
    /** @var null|string */
    public $fallbackAttribute;
    /** @var null|array{seed:string,ranges:array{0:float,1:float}[],hashVersion?:int,attribute?:string}[] */
    public $filters;
    /** @var null|string */
    public $seed;
    /** @var null|int */
    public $hashVersion;
    /** @var null|array{key?:string,name?:string,passthrough?:bool}[] */
    public $meta;
    /** @var null|array{0:float,1:float}[] */
    public $ranges;
    /** @var null|string */
    public $name;
    /** @var null|string */
    public $phase;
    /** @var bool */
    public $disableStickyBucketing;

    /** @var int */
    public $bucketVersion;

    /** @var int */
    public $minBucketVersion;

    /**
     * @param string $key
     * @param T[] $variations
     * @return InlineExperiment<T>
     */
    public static function create(string $key, $variations): InlineExperiment
    {
        return new InlineExperiment($key, $variations);
    }

    /**
     * @param string $key
     * @param T[] $variations
     * @param array{weights?: float[], coverage?: float, force?: int|null, active?: bool, condition?: array<string, mixed>, namespace?: array{0: string, 1: float, 2: float}, hashAttribute?: string, fallbackAttribute?: string, filters?: array{seed: string, ranges: array{0: float, 1: float}[], hashVersion?: int, attribute?: string}[], seed?: string, hashVersion?: int, meta?: array{key?: string, name?: string, passthrough?: bool}[], ranges?: array{0: float, 1: float}[], name?: string, phase?: string, disableStickyBucketing?: bool, bucketVersion?: int, minBucketVersion?: int} $options
     */
    public function __construct(string $key, $variations, array $options = [])
    {
        $this->key = $key;
        $this->variations = $variations;
        $this->weights = $options['weights'] ?? null;
        $this->active = $options["active"] ?? true;
        $this->coverage = $options["coverage"] ?? 1;
        $this->condition = $options["condition"] ?? null;
        $this->namespace = $options["namespace"] ?? null;
        $this->force = $options["force"] ?? null;
        $this->hashAttribute = $options["hashAttribute"] ?? "id";
        $this->filters = $options["filters"] ?? null;
        $this->seed = $options["seed"] ?? null;
        $this->hashVersion = $options["hashVersion"] ?? null;
        $this->meta = $options["meta"] ?? null;
        $this->ranges = $options["ranges"] ?? null;
        $this->name = $options["name"] ?? null;
        $this->phase = $options["phase"] ?? null;
        $this->disableStickyBucketing = $options["disableStickyBucketing"] ?? false;
        $this->bucketVersion = $options["bucketVersion"] ?? 0;
        $this->minBucketVersion = $options["minBucketVersion"] ?? 0;
        $this->fallbackAttribute = null;
        if (!$this->disableStickyBucketing) {
            $this->fallbackAttribute = $options["fallbackAttribute"] ?? null;
        }
    }

    /**
     * @param float[] $weights
     * @return InlineExperiment<T>
     */
    public function withWeights(array $weights): InlineExperiment
    {
        $this->weights = $weights;
        return $this;
    }

    /**
     * @param float $coverage
     * @return InlineExperiment<T>
     */
    public function withCoverage(float $coverage): InlineExperiment
    {
        $this->coverage = $coverage;
        return $this;
    }

    /**
     * @param array<string,mixed> $condition
     * @return InlineExperiment<T>
     */
    public function withCondition(array $condition): InlineExperiment
    {
        $this->condition = $condition;
        return $this;
    }

    /**
     * @param string $namespace
     * @param float $start The start of the namespace range (between 0 and 1)
     * @param float $end The end of the namespace range (between 0 and 1)
     * @return InlineExperiment<T>
     */
    public function withNamespace(string $namespace, float $start, float $end): InlineExperiment
    {
        $this->namespace = [$namespace, $start, $end];
        return $this;
    }

    /**
     * @param string $hashAttribute
     * @return InlineExperiment<T>
     */
    public function withHashAttribute(string $hashAttribute): InlineExperiment
    {
        $this->hashAttribute = $hashAttribute;
        return $this;
    }
}
