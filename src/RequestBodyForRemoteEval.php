<?php

namespace Growthbook;

class RequestBodyForRemoteEval implements \JsonSerializable
{
    /** @var array<string, mixed>|null */
    private ?array $attributes;
    /** @var array<mixed>|null */
    private ?array $forcedFeatures;
    /** @var array<string, int>|null */
    private ?array $forcedVariations;
    private ?string $url;

    /**
     * @param array<string, mixed>|null $attributes
     * @param array<mixed>|null $forcedFeatures Array of arrays containing forced feature values
     * @param array<string, int>|null $forcedVariations Map of feature keys to variation indices
     * @param string|null $url
     */
    public function __construct(
        ?array $attributes = null,
        ?array $forcedFeatures = null,
        ?array $forcedVariations = null,
        ?string $url = null
    ) {
        $this->attributes = $attributes;
        $this->forcedFeatures = $forcedFeatures;
        $this->forcedVariations = $forcedVariations;
        $this->url = $url;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAttributes(): ?array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, mixed>|null $attributes
     * @return void
     */
    public function setAttributes(?array $attributes): void
    {
        $this->attributes = $attributes;
    }

    /**
     * @return array<mixed>|null
     */
    public function getForcedFeatures(): ?array
    {
        return $this->forcedFeatures;
    }

    /**
     * @param array<mixed>|null $forcedFeatures
     * @return void
     */
    public function setForcedFeatures(?array $forcedFeatures): void
    {
        $this->forcedFeatures = $forcedFeatures;
    }

    /**
     * @return array<string, int>|null
     */
    public function getForcedVariations(): ?array
    {
        return $this->forcedVariations;
    }

    /**
     * @param array<string, int>|null $forcedVariations
     * @return void
     */
    public function setForcedVariations(?array $forcedVariations): void
    {
        $this->forcedVariations = $forcedVariations;
    }

    /**
     * @return string|null
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * @param string|null $url
     * @return void
     */
    public function setUrl(?string $url): void
    {
        $this->url = $url;
    }

    /**
     * Implementation of JsonSerializable interface (PHP's equivalent to Java's toJson)
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [];
        
        if ($this->attributes !== null) {
            $data['attributes'] = $this->attributes;
        }
        
        if ($this->forcedFeatures !== null) {
            $data['forcedFeatures'] = $this->forcedFeatures;
        }
        
        if ($this->forcedVariations !== null) {
            $data['forcedVariations'] = $this->forcedVariations;
        }
        
        if ($this->url !== null) {
            $data['url'] = $this->url;
        }
        
        return $data;
    }
}