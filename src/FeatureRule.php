<?php

namespace Growthbook;

/**
 * @template T
 */
class FeatureRule
{
    /** @var null|string */
    public $id;
    /** @var null|array<string,mixed> */
    public $condition;
    /** @var null|float */
    public $coverage;
    /** @var null|T */
    public $force;
    /** @var null|T[] */
    public $variations;
    /** @var null|string */
    public $key;
    /** @var null|float[] */
    public $weights;
    /** @var null|array{0:string,1:float,2:float} */
    public $namespace;
    /** @var null|string */
    public $hashAttribute;
    /** @var null|string */
    public $fallbackAttribute;
    /** @var null|array{seed:string,ranges:array{0:float,1:float}[],hashVersion?:int,attribute?:string}[] */
    public $filters;
    /** @var null|string */
    public $seed;
    /** @var null|int */
    public $hashVersion;
    /** @var null|array{0:float,1:float} */
    public $range;
    /** @var null|array{key?:string,name?:string,passthrough?:bool}[] */
    public $meta;
    /** @var null|array{0:float,1:float}[] */
    public $ranges;
    /** @var null|string */
    public $name;
    /** @var null|string */
    public $phase;
    /** @var null|bool */
    public $disableStickyBucketing;

    /** @var null|int */
    public $bucketVersion;

    /** @var null|int */
    public $minBucketVersion;

    /** @var null|array<string, mixed> */
    public $parentConditions;

    /**
     * @param array{id?:string,condition:?array<string,mixed>,coverage:?float,force:?T,variations:?T[],key:?string,weights:?float[],namespace:?array{0:string,1:float,2:float},hashAttribute:?string,fallbackAttribute:?string,filters?:array{seed:string,ranges:array{0:float,1:float}[],hashVersion?:int,attribute?:string}[],seed?:string,hashVersion?:int,range?:array{0:float,1:float},meta?:array{key?:string,name?:string,passthrough?:bool}[],ranges?:array{0:float,1:float}[],name?:string,phase?:string,disableStickyBucketing:?bool,bucketVersion:?int,minBucketVersion:?int,parentConditions:?array<string,mixed>} $rule
     */
    public function __construct(array $rule)
    {
        if (array_key_exists("id", $rule)) {
            $this->id = $rule["id"];
        }
        if (array_key_exists("condition", $rule)) {
            $this->condition = $rule["condition"];
        }
        if (array_key_exists("coverage", $rule)) {
            $this->coverage = $rule["coverage"];
        }
        if (array_key_exists("force", $rule)) {
            $this->force = $rule["force"];
        }
        if (array_key_exists("variations", $rule)) {
            $this->variations = $rule["variations"];
        }
        if (array_key_exists("key", $rule)) {
            $this->key = $rule["key"];
        }
        if (array_key_exists("weights", $rule)) {
            $this->weights = $rule["weights"];
        }
        if (array_key_exists("namespace", $rule)) {
            $this->namespace = $rule["namespace"];
        }
        if (array_key_exists("hashAttribute", $rule)) {
            $this->hashAttribute = $rule["hashAttribute"];
        }
        if (array_key_exists('fallbackAttribute', $rule)) {
            $this->fallbackAttribute = $rule['fallbackAttribute'];
        }
        if (array_key_exists("filters", $rule)) {
            $this->filters = $rule["filters"];
        }
        if (array_key_exists("seed", $rule)) {
            $this->seed = $rule["seed"];
        }
        if (array_key_exists("hashVersion", $rule)) {
            $this->hashVersion = $rule["hashVersion"];
        }
        if (array_key_exists("range", $rule)) {
            $this->range = $rule["range"];
        }
        if (array_key_exists("meta", $rule)) {
            $this->meta = $rule["meta"];
        }
        if (array_key_exists("ranges", $rule)) {
            $this->ranges = $rule["ranges"];
        }
        if (array_key_exists("name", $rule)) {
            $this->name = $rule["name"];
        }
        if (array_key_exists("phase", $rule)) {
            $this->phase = $rule["phase"];
        }
        if (array_key_exists("disableStickyBucketing", $rule)) {
            $this->disableStickyBucketing = $rule["disableStickyBucketing"];
        }
        if (array_key_exists("bucketVersion", $rule)) {
            $this->bucketVersion = $rule["bucketVersion"];
        }
        if (array_key_exists("minBucketVersion", $rule)) {
            $this->minBucketVersion = $rule["minBucketVersion"];
        }
        if (array_key_exists("parentConditions", $rule)) {
            $this->parentConditions = $rule["parentConditions"];
        }
    }

    /**
     * @param string $featureKey
     * @return null|InlineExperiment<T>
     */
    public function toExperiment(string $featureKey): ?InlineExperiment
    {
        if (!isset($this->variations)) {
            return null;
        }

        $exp = new InlineExperiment($this->key ?? $featureKey, $this->variations);

        if (isset($this->coverage)) {
            $exp->coverage = $this->coverage;
        }
        if (isset($this->weights)) {
            $exp->weights = $this->weights;
        }
        if (isset($this->hashAttribute)) {
            $exp->hashAttribute = $this->hashAttribute;
        }
        if (isset($this->fallbackAttribute)) {
            $exp->fallbackAttribute = $this->fallbackAttribute;
        }
        if (isset($this->namespace)) {
            $exp->namespace = $this->namespace;
        }
        if (isset($this->meta)) {
            $exp->meta = $this->meta;
        }
        if (isset($this->ranges)) {
            $exp->ranges = $this->ranges;
        }
        if (isset($this->name)) {
            $exp->name = $this->name;
        }
        if (isset($this->phase)) {
            $exp->phase = $this->phase;
        }
        if (isset($this->seed)) {
            $exp->seed = $this->seed;
        }
        if (isset($this->filters)) {
            $exp->filters = $this->filters;
        }
        if (isset($this->hashVersion)) {
            $exp->hashVersion = $this->hashVersion;
        }
        if (isset($this->disableStickyBucketing)) {
            $exp->disableStickyBucketing = $this->disableStickyBucketing;
        }
        if (isset($this->bucketVersion)) {
            $exp->bucketVersion = $this->bucketVersion;
        }
        if (isset($this->minBucketVersion)) {
            $exp->minBucketVersion = $this->minBucketVersion;
        }
        if (isset($this->condition)) {
            $exp->condition = $this->condition;
        }
        if (isset($this->parentConditions)) {
            $exp->parentConditions = $this->parentConditions;
        }

        return $exp;
    }
}
