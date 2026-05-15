<?php

namespace Growthbook;

/**
 * Holds all registered plugins and dispatches lifecycle and evaluation
 * events to each one. A failure in one plugin never affects the others.
 */
final class PluginRegistry
{
    /** @var Plugin[] */
    private array $plugins;

    /** @param Plugin[] $plugins */
    public function __construct(array $plugins = [])
    {
        $this->plugins = $plugins;
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
     */
    public function onExperimentViewed(InlineExperiment $experiment, ExperimentResult $result): void
    {
        foreach ($this->plugins as $plugin) {
            $this->safeCall($plugin, fn() => $plugin->onExperimentViewed($experiment, $result));
        }
    }

    /**
     * @param FeatureResult<mixed> $result
     */
    public function onFeatureEvaluated(string $featureKey, FeatureResult $result): void
    {
        foreach ($this->plugins as $plugin) {
            $this->safeCall($plugin, fn() => $plugin->onFeatureEvaluated($featureKey, $result));
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
            // never propagate plugin errors
        }
    }
}
