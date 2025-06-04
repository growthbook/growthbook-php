<?php

namespace Growthbook\Plugins;

use Growthbook\Growthbook;

/**
 * Base class for plugins that ensures GrowthBook instance is always available
 * 
 * This class handles the initialization lifecycle and provides a guaranteed non-null
 * GrowthBook instance to child plugins through the protected $growthbook property.
 */
abstract class GrowthbookPlugin
{
    /** @var Growthbook */
    protected $growthbook;

    /** @var bool */
    private $initialized = false;

    /**
     * Constructor stores options but plugin is not yet ready for use
     * @param array<string,mixed> $options
     */
    abstract public function __construct(array $options = []);

    /**
     * Automatically called when a plugin is added
     * @param Growthbook $growthbook
     */
    final public function initialize(Growthbook $growthbook): void
    {
        $this->growthbook = $growthbook;
        $this->initialized = true;

        // Call the plugin-specific setup
        $this->setup();
    }

    // Plugin-specific setup method
    abstract public function setup(): void;

    /**
     * Get the GrowthBook instance - guaranteed to be non-null after initialization
     * @return Growthbook
     * @throws \RuntimeException if called before initialization
     */
    protected function getGrowthbook(): Growthbook
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Plugin not yet initialized. GrowthBook instance not available.');
        }
        return $this->growthbook;
    }
}
