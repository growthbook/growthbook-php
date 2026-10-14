<?php

namespace Growthbook;

/**
 * Implemented by plugins that want the custom events an application sends with
 * `Growthbook::logEvent()`, alongside the SDK's own experiment and feature events.
 *
 * Kept separate from `Plugin` on purpose: adding a method there would break every existing
 * implementation. A plugin opts in by implementing both interfaces, and the registry only
 * dispatches custom events to those that do.
 */
interface EventLoggingPlugin
{
    /**
     * @param string              $eventName  Name the application logged the event under
     * @param array<string,mixed> $properties Arbitrary event properties
     * @param array<string,mixed> $attributes User attributes at the time the event was logged
     */
    public function onLogEvent(string $eventName, array $properties, array $attributes): void;
}
