<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Growthbook\Growthbook;
use Growthbook\Plugins\GrowthbookPlugin;
use PHPUnit\Framework\TestCase;

class MockPlugin extends GrowthbookPlugin
{
    /** @var array<string,mixed> */
    private $options = [];

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function setup(): void {}
}

class GrowthbookPluginsTest extends TestCase
{
    public function testPluginLifecycle(): void
    {
        // Test the new AbstractPlugin pattern that guarantees non-null GrowthBook
        $options = ['testOption' => 'testValue', 'enabled' => true];

        // Create plugin with just options
        $plugin = new MockPlugin($options);

        // Use it with GrowthBook - this will call onInitialize()
        $growthbook = new Growthbook([
            'plugins' => [
                $plugin
            ]
        ]);

        // TODO add validations that the options were properly passed and setup method were called
    }
}
