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

    /**
     * Plugins this registry dispatches to but does not own: they belong to the registry it was
     * detached from, which stays alive through [parent] and closes them.
     *
     * @var Plugin[]
     */
    private array $inherited = [];

    /** Registry this one was detached from, kept alive for as long as this one lives. */
    private ?PluginRegistry $parent = null;

    private ?LoggerInterface $logger;

    /**
     * Client key the plugins were keyed with, or null while they are still unkeyed. Plugins are
     * keyed once: copies made with the fluent methods share this registry, so letting a copy
     * re-key it would redirect the original's events - and any already buffered by a shared
     * plugin - to the copy's connection.
     */
    private ?string $clientKey = null;

    /**
     * Bumped whenever the audience for events changes - a plugin is added, or the plugins are
     * given their client key. Instances sharing this registry compare it against the generation
     * their own "already reported" history belongs to, so a handle that evaluated something
     * before tracking was wired up does not keep suppressing it afterwards.
     */
    private int $generation = 0;

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
        return $this->plugins !== [] || $this->inherited !== [];
    }

    /**
     * A copy of this registry that one handle can write to on its own.
     *
     * The plugins registered so far keep receiving the handle's events - they were registered
     * for it too - but anything added afterwards reaches only the handle that added it. The
     * plugin objects are not duplicated and not re-initialized: they stay owned by this
     * registry, which the detached one keeps alive, so they are still closed exactly once and
     * only after every handle is done with them.
     *
     * @return PluginRegistry
     */
    public function detach(): PluginRegistry
    {
        $detached = new PluginRegistry([], $this->logger);
        $detached->inherited = array_merge($this->inherited, $this->plugins);
        $detached->clientKey = $this->clientKey;
        $detached->generation = $this->generation;
        $detached->parent = $this;
        $this->retain();

        return $detached;
    }

    /**
     * @return Plugin[]
     */
    private function dispatchTargets(): array
    {
        return $this->inherited === [] ? $this->plugins : array_merge($this->inherited, $this->plugins);
    }

    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * Whether more than one Growthbook instance holds this registry.
     */
    public function isShared(): bool
    {
        return $this->refCount > 1;
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

        $this->generation++;
    }

    public function initialize(string $clientKey): void
    {
        // Nothing to key yet: remembering the key here would pin the plugins to whichever key
        // happened to be set first, even though they only arrive later and the instance may have
        // moved on by then
        if ($this->plugins === []) {
            return;
        }

        if ($this->clientKey !== null) {
            if ($this->clientKey !== $clientKey && $this->logger) {
                $this->logger->log(LogLevel::WARNING, "Plugins are already sending to another client key, keeping it", [
                    "ignored" => $clientKey,
                ]);
            }
            return;
        }

        $this->clientKey = $clientKey;
        $this->generation++;
        foreach ($this->dispatchTargets() as $plugin) {
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
        foreach ($this->dispatchTargets() as $plugin) {
            $this->safeCall($plugin, fn() => $plugin->onExperimentViewed($experiment, $result, $attributes));
        }
    }

    /**
     * @param FeatureResult<mixed> $result
     * @param array<string,mixed>  $attributes
     */
    public function onFeatureEvaluated(string $featureKey, FeatureResult $result, array $attributes): void
    {
        foreach ($this->dispatchTargets() as $plugin) {
            $this->safeCall($plugin, fn() => $plugin->onFeatureEvaluated($featureKey, $result, $attributes));
        }
    }

    /**
     * Dispatch a custom event to the plugins that opted in by implementing EventLoggingPlugin.
     *
     * @param string              $eventName
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $attributes
     */
    public function onLogEvent(string $eventName, array $properties, array $attributes): void
    {
        foreach ($this->dispatchTargets() as $plugin) {
            if (!$plugin instanceof EventLoggingPlugin) {
                continue;
            }
            $this->safeCall($plugin, fn() => $plugin->onLogEvent($eventName, $properties, $attributes));
        }
    }

    public function close(): void
    {
        // Only what this registry owns: the inherited plugins are closed by the registry they
        // belong to, once this one has let go of it.
        foreach ($this->plugins as $plugin) {
            $this->safeCall($plugin, fn() => $plugin->close());
        }

        $parent = $this->parent;
        $this->parent = null;
        if ($parent !== null) {
            $parent->release();
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
