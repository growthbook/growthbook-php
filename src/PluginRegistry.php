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
     * Client key the plugins were keyed with, or null while they are still unkeyed. Plugins are
     * keyed once: copies made with the fluent methods share this registry, so letting a copy
     * re-key it would redirect the original's events - and any already buffered by a shared
     * plugin - to the copy's connection.
     */
    private ?string $clientKey = null;

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

    public function hasPlugins(): bool
    {
        return $this->plugins !== [];
    }

    /**
     * Whether the plugins are already sending to a different client key than the one given.
     */
    public function isKeyedWithOther(string $clientKey): bool
    {
        return $this->clientKey !== null && $this->clientKey !== $clientKey;
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param Plugin      $plugin
     * @param string|null $clientKey Initializes the plugin right away when already known
     */
    public function add(Plugin $plugin, ?string $clientKey = null): void
    {
        $this->plugins[] = $plugin;

        // A plugin arriving after the key is known is keyed straight away, otherwise it would
        // never receive initialize() at all
        $key = $this->clientKey ?? $clientKey;
        if ($key !== null && $key !== "") {
            $this->clientKey = $key;
            $this->safeCall($plugin, fn() => $plugin->initialize($key));
        }
    }

    public function initialize(string $clientKey): void
    {
        if ($this->clientKey !== null) {
            if ($this->clientKey !== $clientKey && $this->logger) {
                $this->logger->log(LogLevel::WARNING, "Plugins are already sending to another client key, keeping it", [
                    "ignored" => $clientKey,
                ]);
            }
            return;
        }

        $this->clientKey = $clientKey;
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
