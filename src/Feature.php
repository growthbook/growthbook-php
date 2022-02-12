<?php

namespace Growthbook;

/**
 * @template T
 */
class Feature
{
    /** @var null|T */
    public $defaultValue = null;
    /** @var FeatureRule<T>[] */
    public $rules = [];

    /**
     * @param array{defaultValue:T,rules:?array{condition:?array<string,mixed>,coverage:?float,force:?T,variations:?T[],key:?string,weights:?float[],namespace:?array{0:string,1:float,2:float},hashAttribute:?string}[]} $feature
     */
    public function __construct(array $feature)
    {
        if (array_key_exists("defaultValue", $feature)) {
            $this->defaultValue = $feature["defaultValue"];
        }
        if (array_key_exists("rules", $feature)) {
            $rules = $feature["rules"];
            if (is_array($rules)) {
                foreach ($rules as $rule) {
                    if (is_array($rule)) {
                        $this->rules[] = new FeatureRule($rule);
                    }
                }
            }
        }
    }
}
