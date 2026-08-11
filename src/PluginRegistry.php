<?php

namespace Growthbook;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Holds all registered plugins and dispatches lifecycle and evaluation
 * events to each one. A failure in one plugin never affects the others.
 */
final class PluginRegistry
{
    /** @var Plugin[] */
    private array $plugins;

    private ?LoggerInterface $logger;

    /**
     * Number of Growthbook instances sharing this registry. A registry is shared by every copy
     * made with the fluent `withX()` methods (PHP clones are shallow), so plugins must stay open
     * until the last of those copies is gone - see [retain] / [release].
     *
     * @var int
     */
    private int $refCount = 1;

    /**
     * @param Plugin[]             $plugins
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $plugins = [], ?LoggerInterface $logger = null)
    {
        $this->plugins = $plugins;
        $this->logger  = $logger;
    }

    /**
     * Register another Growthbook instance as an owner of this registry.
     */
    public function retain(): void
    {
        $this->refCount++;
    }

    /**
     * Drop an owner and close the plugins once the last one is gone. Closing on the first owner
     * to be destroyed would shut the plugins down for every remaining copy - a scoped instance
     * built with `withAttributes()` going out of scope would silently stop the original's
     * tracking, because a closed plugin never flushes again.
     */
    public function release(): void
    {
        $this->refCount--;
        if ($this->refCount <= 0) {
            $this->close();
        }
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function add(Plugin $plugin): void
    {
        $this->plugins[] = $plugin;
    }

    public function initialize(string $clientKey): void
    {
        foreach ($this->plugins as $plugin) {
            $this->safeCall($plugin, fn() => $plugin->initialize($clientKey));
        }
    }

    /**
     * @param InlineExperiment<mixed> $experiment
     * @param ExperimentResult<mixed> $result
     * @param array<string,mixed>     $attributes
     */
    public function onExperimentViewed(InlineExperiment $experiment, ExperimentResult $result, array $attributes): void
    {
        foreach ($this->plugins as $plugin) {
            $this->safeCall($plugin, fn() => $plugin->onExperimentViewed($experiment, $result, $attributes));
        }
    }

    /**
     * @param FeatureResult<mixed> $result
     * @param array<string,mixed>  $attributes
     */
    public function onFeatureEvaluated(string $featureKey, FeatureResult $result, array $attributes): void
    {
        foreach ($this->plugins as $plugin) {
            $this->safeCall($plugin, fn() => $plugin->onFeatureEvaluated($featureKey, $result, $attributes));
        }
    }

    public function close(): void
    {
        foreach ($this->plugins as $plugin) {
            $this->safeCall($plugin, fn() => $plugin->close());
        }
    }

    private function safeCall(Plugin $plugin, \Closure $block): void
    {
        try {
            $block();
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->log(LogLevel::ERROR, "Plugin error in " . get_class($plugin), [
                    "error" => $e->getMessage(),
                    "plugin" => get_class($plugin),
                ]);
            }
        }
    }
}
