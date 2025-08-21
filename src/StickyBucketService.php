<?php

namespace Growthbook;

use React\Promise\Promise;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\EventLoop\LoopInterface;

abstract class StickyBucketService
{
    /** @var bool */
    private static $asyncMode = false;
    
    /** @var LoopInterface|null */
    private $loop = null;

    /**
     * Abstract methods for implementations to provide sync versions
     */
    /** @phpstan-ignore-next-line */
    abstract protected function getAssignmentsSync(string $attributeName, $attributeValue): ?array;
    /** @phpstan-ignore-next-line */
    abstract protected function saveAssignmentsSync(array $doc): void;

    /**
     * Default async implementation that wraps the sync method
     * @param string $attributeName
     * @param mixed $attributeValue
     * @return PromiseInterface<array<string,mixed>|null>
     */
    public function getAssignmentsAsync(string $attributeName, $attributeValue): PromiseInterface
    {
        $deferred = new Deferred();
        
        // Use futureTick to make it truly non-blocking
        \React\EventLoop\Loop::get()->futureTick(function () use ($deferred, $attributeName, $attributeValue) {
            try {
                $result = $this->getAssignmentsSync($attributeName, $attributeValue);
                $deferred->resolve($result);
            } catch (\Throwable $error) {
                $deferred->reject($error);
            }
        });
        
        return $deferred->promise();
    }

    /**
     * Default async implementation that wraps the sync method
     * @param array<string,mixed> $doc
     * @return PromiseInterface<void>
     */
    public function saveAssignmentsAsync(array $doc): PromiseInterface
    {
        $deferred = new Deferred();

        // Use futureTick to make it truly non-blocking
        \React\EventLoop\Loop::get()->futureTick(function () use ($deferred, $doc) {
            try {
                $this->saveAssignmentsSync($doc);
                $deferred->resolve(null);
            } catch (\Throwable $error) {
                $deferred->reject($error);
            }
        });

        return $deferred->promise();
    }

    /**
     * Enable async mode
     * @return void
     */
    public static function enableAsyncMode(): void
    {
        self::$asyncMode = true;
    }

    /**
     * Disable async mode
     * @return void
     */
    public static function disableAsyncMode(): void
    {
        self::$asyncMode = false;
    }

    /**
     * Auto-detecting getAssignments - returns Promise if event loop is running, otherwise sync result
     * @param string $attributeName
     * @param string $attributeValue
     * @return array<string,mixed>|null|PromiseInterface<array<string,mixed>|null>
     */
    public function getAssignments(string $attributeName, string $attributeValue)
    {
        // Auto-detect: if event loop is running, return promise
        if ($this->isEventLoopRunning()) {
            return $this->getAssignmentsAsync($attributeName, $attributeValue);
        }

        // Otherwise, sync behavior
        return $this->getAssignmentsSync($attributeName, $attributeValue);
    }

    /**
     * Auto-detecting saveAssignments - returns Promise if event loop is running, otherwise void
     * @param array<string,mixed> $doc
     * @return void|PromiseInterface<void>
     */
    public function saveAssignments(array $doc)
    {
        // Auto-detect: if event loop is running, return promise
        if ($this->isEventLoopRunning()) {
            return $this->saveAssignmentsAsync($doc);
        }
        
        // Otherwise, sync behavior
        $this->saveAssignmentsSync($doc);
    }

    /**
     * Auto-detecting getAllAssignments - returns Promise if event loop is running, otherwise array
     * @param array<string, string> $attributes
     * @return array<string, array<string,mixed>>|PromiseInterface<array<string, array<string,mixed>>>
     */
    public function getAllAssignments(array $attributes)
    {
        // Auto-detect: if event loop is running, return promise
        if ($this->isEventLoopRunning()) {
            return $this->getAllAssignmentsAsync($attributes);
        }
        
        // Otherwise, sync behavior
        $docs = [];
        foreach ($attributes as $attributeName => $attributeValue) {
            $doc = $this->getAssignmentsSync($attributeName, $attributeValue);
            if ($doc) {
                $docs[$this->getKey($attributeName, $attributeValue)] = $doc;
            }
        }
        return $docs;
    }

    /**
     * @param string $attributeName
     * @param string $attributeValue
     * @return string
     */
    public function getKey(string $attributeName, string $attributeValue): string
    {
        return "{$attributeName}||{$attributeValue}";
    }

    /**
     * Check if we should use async mode
     * @return bool
     */
    protected function isEventLoopRunning(): bool
    {
        // Check static flag first (set by futureTick contexts)
        if (self::$asyncMode) {
            return true;
        }
        
        try {
            $loop = \React\EventLoop\Loop::get();
            // For compatibility, check if loop has isRunning method
            return method_exists($loop, 'isRunning') && $loop->isRunning();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Async version of getAllAssignments using ReactPHP promises
     * @param array<string, string> $attributes
     * @return PromiseInterface<array<string, array<string,mixed>>>
     */
    public function getAllAssignmentsAsync(array $attributes): PromiseInterface
    {
        $deferred = new Deferred();
        
        $promises = [];
        foreach ($attributes as $attributeName => $attributeValue) {
            $promises[] = $this->getAssignmentsAsync($attributeName, $attributeValue)
                ->then(function ($doc) use ($attributeName, $attributeValue) {
                    return $doc ? [$this->getKey($attributeName, $attributeValue) => $doc] : [];
                });
        }
        
        \React\Promise\all($promises)->then(
            function ($results) use ($deferred) {
                $docs = [];
                foreach ($results as $result) {
                    $docs = array_merge($docs, $result);
                }
                $deferred->resolve($docs);
            },
            function ($error) use ($deferred) {
                $deferred->reject($error);
            }
        );
        
        return $deferred->promise();
    }

    /**
     * Get or create the ReactPHP event loop instance
     * @return LoopInterface
     */
    public function getLoop(): LoopInterface
    {
        if ($this->loop === null) {
            $this->loop = \React\EventLoop\Loop::get();
        }
        return $this->loop;
    }

    /**
     * Run the ReactPHP event loop to execute pending promises
     * This method handles the loop execution internally, so users don't need to manage it
     * @return void
     */
    public function run(): void
    {
        $this->getLoop()->run();
    }

    /**
     * Convenient method to resolve all promises concurrently
     * 
     * @param array<PromiseInterface<mixed>> $promises Array of promises to resolve
     * @return PromiseInterface<array<mixed>> Promise that resolves when all input promises resolve
     * 
     * Example:
     * $promises = [
     *     $service->getAssignmentsAsync('user_id', '1'),
     *     $service->getAssignmentsAsync('user_id', '2'),
     *     $service->getAssignmentsAsync('user_id', '3'),
     * ];
     * $service->resolveAll($promises)->then(function($results) {
     *     // $results is array of all resolved values
     * });
     */
    public function resolveAll(array $promises): PromiseInterface
    {
        return \React\Promise\all($promises);
    }
}