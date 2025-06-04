<?php

namespace Growthbook;

interface EventLoggerInterface
{
    /**
     * @param string $eventName
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $context
     */
    public function logEvent(string $eventName, array $properties, array $context): void;
}
