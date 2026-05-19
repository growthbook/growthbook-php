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
     * @param Plugin[]             $plugins
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $plugins = [], ?LoggerInterface $logger = null)
    {
        $this->plugins = $plugins;
        $this->logger  = $logger;
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
