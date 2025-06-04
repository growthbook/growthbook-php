<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Growthbook\Growthbook;
use Growthbook\Plugins\GrowthbookPlugin;
use PHPUnit\Framework\TestCase;

class GrowthbookPluginsTest extends TestCase
{
    public function testPluginLifecycle(): void
    {
        $options = ['testOption' => 'testValue', 'enabled' => true];

        $plugin = new MockPlugin($options);

        $this->assertEquals($options, $plugin->getOptions());

        $this->assertEquals(0, $plugin->getSetupCallCount());
        $this->assertFalse($plugin->isInitialized());

        $growthbook = new Growthbook([
            'plugins' => [$plugin]
        ]);

        $this->assertEquals(1, $plugin->getSetupCallCount());
        $this->assertTrue($plugin->isInitialized());
        $this->assertSame($growthbook, $plugin->getGrowthbookForTesting());
    }

    public function testMultiplePlugins(): void
    {
        $plugin1 = new MockPlugin(['name' => 'plugin1']);
        $plugin2 = new MockPlugin(['name' => 'plugin2']);

        new Growthbook(['plugins' => [$plugin1, $plugin2]]);

        $this->assertEquals(1, $plugin1->getSetupCallCount());
        $this->assertEquals(1, $plugin2->getSetupCallCount());
        $this->assertTrue($plugin1->isInitialized());
        $this->assertTrue($plugin2->isInitialized());
        $this->assertEquals(['name' => 'plugin1'], $plugin1->getOptions());
        $this->assertEquals(['name' => 'plugin2'], $plugin2->getOptions());
    }

    public function testFluentApi(): void
    {
        $plugin1 = new MockPlugin(['name' => 'plugin1']);
        $plugin2 = new MockPlugin(['name' => 'plugin2']);

        (new Growthbook())->withPlugin($plugin1)->withPlugin($plugin2);

        $this->assertEquals(1, $plugin1->getSetupCallCount());
        $this->assertEquals(1, $plugin2->getSetupCallCount());
        $this->assertTrue($plugin1->isInitialized());
        $this->assertTrue($plugin2->isInitialized());
        $this->assertEquals(['name' => 'plugin1'], $plugin1->getOptions());
        $this->assertEquals(['name' => 'plugin2'], $plugin2->getOptions());
    }
}

class MockPlugin extends GrowthbookPlugin
{
    /** @var array<string,mixed> */
    private $options = [];

    /** @var int */
    private $setupCallCount = 0;

    /** @var bool */
    private $isInitialized = false;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function setup(): void
    {
        $this->setupCallCount++;
        $this->isInitialized = true;
    }

    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getSetupCallCount(): int
    {
        return $this->setupCallCount;
    }

    public function isInitialized(): bool
    {
        return $this->isInitialized;
    }

    public function getGrowthbookForTesting(): Growthbook
    {
        return $this->growthbook;
    }
}
