<?php

use PHPUnit\Framework\TestCase;
use Growthbook\InMemoryStickyBucketService;

class StickyBucketServiceTest extends TestCase
{
    private InMemoryStickyBucketService $service;

    protected function setUp(): void
    {
        $this->service = new InMemoryStickyBucketService();
    }

    public function testGetAssignmentsSync(): void
    {
        // Test sync behavior
        $doc = [
            'attributeName' => 'user_id',
            'attributeValue' => '123',
            'assignments' => ['experiment1' => 'variation1']
        ];
        
        $this->service->saveAssignments($doc);
        $result = $this->service->getAssignments('user_id', '123');
        
        $this->assertEquals($doc, $result);
    }

    public function testSaveAssignmentsSync(): void
    {
        $doc = [
            'attributeName' => 'session_id',
            'attributeValue' => 'abc123',
            'assignments' => ['experiment3' => 'control']
        ];
        
        $this->service->saveAssignments($doc);
        $result = $this->service->getAssignments('session_id', 'abc123');
        
        $this->assertEquals($doc, $result);
    }

    public function testGetAllAssignmentsSync(): void
    {
        // Setup test data
        $doc1 = [
            'attributeName' => 'user_id',
            'attributeValue' => '111',
            'assignments' => ['exp1' => 'var1']
        ];
        $doc2 = [
            'attributeName' => 'user_id', 
            'attributeValue' => '222',
            'assignments' => ['exp2' => 'var2']
        ];
        
        $this->service->saveAssignments($doc1);
        $this->service->saveAssignments($doc2);
        
        $attributes = ['user_id' => '111', 'session_id' => '333'];
        $result = $this->service->getAllAssignments($attributes);
        
        // Should only find user_id||111, not session_id||333
        $this->assertIsArray($result); // Ensure we're in sync mode
        $expectedKey1 = $this->service->getKey('user_id', '111');
        $nonExistentKey = $this->service->getKey('session_id', '333');
        /** @phpstan-ignore-next-line */
        $this->assertArrayHasKey($expectedKey1, $result);
        /** @phpstan-ignore-next-line */
        $this->assertArrayNotHasKey($nonExistentKey, $result);
        /** @phpstan-ignore-next-line */
        $this->assertEquals($doc1, $result[$expectedKey1]);
    }

    public function testGetKeyFormat(): void
    {
        $key = $this->service->getKey('user_id', '12345');
        $this->assertEquals('user_id||12345', $key);
    }

    public function testDestroy(): void
    {
        $doc = [
            'attributeName' => 'user_id',
            'attributeValue' => '999', 
            'assignments' => ['exp' => 'var']
        ];
        
        $this->service->saveAssignments($doc);
        $this->assertNotNull($this->service->getAssignments('user_id', '999'));
        
        $this->service->destroy();
        $this->assertNull($this->service->getAssignments('user_id', '999'));
    }

    public function testGetAssignmentsTrulyAsync(): void
    {
        // Test truly async behavior using ReactPHP promises
        $doc = [
            'attributeName' => 'user_id',
            'attributeValue' => '987',
            'assignments' => ['async_exp' => 'async_var']
        ];
        
        $this->service->saveAssignments($doc);
        
        $executed = false;
        $result = null;
        
        $promise = $this->service->getAssignmentsAsync('user_id', '987');
        $promise->then(function ($retrievedDoc) use (&$executed, &$result) {
            $executed = true;
            $result = $retrievedDoc;
        });
        
        // Promise should not be executed immediately (non-blocking)
        $this->assertFalse($executed);
        
        // Run the event loop to execute the promise
        \React\EventLoop\Loop::get()->run();
        
        // Now it should be executed
        $this->assertTrue($executed);
        $this->assertEquals($doc, $result);
    }

    public function testSaveAssignmentsTrulyAsync(): void
    {
        $doc = [
            'attributeName' => 'async_user',
            'attributeValue' => '654', 
            'assignments' => ['async_test' => 'async_treatment']
        ];
        
        $executed = false;
        
        $promise = $this->service->saveAssignmentsAsync($doc);
        $promise->then(function () use (&$executed) {
            $executed = true;
        });
        
        // Promise should not be executed immediately (non-blocking)
        $this->assertFalse($executed);
        
        // Run the event loop to execute the promise
        \React\EventLoop\Loop::get()->run();
        
        // Now it should be executed and doc should be saved
        $this->assertTrue($executed);
        $this->assertEquals($doc, $this->service->getAssignments('async_user', '654'));
    }

    public function testGetAllAssignmentsTrulyAsync(): void
    {
        // Setup test data
        $doc1 = [
            'attributeName' => 'async_session',
            'attributeValue' => 'sess_async_1',
            'assignments' => ['async_exp1' => 'async_var1']
        ];
        $doc2 = [
            'attributeName' => 'async_session',
            'attributeValue' => 'sess_async_2', 
            'assignments' => ['async_exp2' => 'async_var2']
        ];
        
        $this->service->saveAssignments($doc1);
        $this->service->saveAssignments($doc2);
        
        $executed = false;
        $result = null;
        
        $attributes = ['async_session' => 'sess_async_1', 'async_session2' => 'sess_async_2'];
        $promise = $this->service->getAllAssignmentsAsync($attributes);
        $promise->then(function ($docs) use (&$executed, &$result) {
            $executed = true;
            $result = $docs;
        });
        
        // Promise should not be executed immediately (non-blocking)
        $this->assertFalse($executed);
        
        // Run the event loop to execute the promise
        \React\EventLoop\Loop::get()->run();
        
        // Now it should be executed
        $this->assertTrue($executed);
        $expectedKey1 = $this->service->getKey('async_session', 'sess_async_1');
        $this->assertArrayHasKey($expectedKey1, $result);
        $this->assertEquals($doc1, $result[$expectedKey1]);
    }

    public function testAsyncErrorHandlingWithPromises(): void
    {
        // Create a mock service that throws exceptions in async methods
        $mockService = new class extends InMemoryStickyBucketService {
            public function getAssignmentsAsync(string $attributeName, $attributeValue): \React\Promise\PromiseInterface
            {
                $deferred = new \React\Promise\Deferred();
                
                \React\EventLoop\Loop::get()->futureTick(function () use ($deferred) {
                    $deferred->reject(new \Exception('Async test error'));
                });
                
                return $deferred->promise();
            }
        };
        
        $executed = false;
        $error = null;
        
        $promise = $mockService->getAssignmentsAsync('user_id', '123');
        $promise->then(
            function ($doc) use (&$executed) {
                $executed = true;
            },
            function ($err) use (&$executed, &$error) {
                $executed = true;
                $error = $err;
            }
        );
        
        // Promise should not be executed immediately
        $this->assertFalse($executed);
        
        // Run the event loop to execute the promise
        \React\EventLoop\Loop::get()->run();
        
        // Now error should be caught
        $this->assertTrue($executed);
        $this->assertInstanceOf(\Exception::class, $error);
        $this->assertEquals('Async test error', $error->getMessage());
    }

    public function testAutoAsyncDetection(): void
    {
        // Test that methods automatically return promises when event loop is running
        $doc = [
            'attributeName' => 'auto_user',
            'attributeValue' => '999',
            'assignments' => ['auto_exp' => 'auto_var']
        ];
        
        // Start event loop in background
        $loop = \React\EventLoop\Loop::get();
        
        // Without event loop running - should return sync results
        $syncResult = $this->service->getAssignments('auto_user', '999');
        $this->assertNull($syncResult); // No data yet
        
        $this->service->saveAssignments($doc);
        $syncResult = $this->service->getAssignments('auto_user', '999');
        $this->assertEquals($doc, $syncResult);
        
        // Now simulate event loop running and test auto-detection
        $executed = false;
        $result = null;
        
        // Use futureTick to simulate event loop is active
        $loop->futureTick(function () use (&$executed, &$result) {
            // Enable async mode for auto-detection inside event loop context
            \Growthbook\StickyBucketService::enableAsyncMode();
            
            // Within event loop context, methods should return promises
            $promise = $this->service->getAssignments('auto_user', '999');
            $this->assertInstanceOf(\React\Promise\PromiseInterface::class, $promise);
            
            /** @phpstan-ignore-next-line */
            $promise->then(function ($retrievedDoc) use (&$executed, &$result) {
                $executed = true;
                $result = $retrievedDoc;
                
                // Disable async mode after test
                \Growthbook\StickyBucketService::disableAsyncMode();
            });
        });
        
        // Run loop to execute the test
        $loop->run();
        
        $this->assertTrue($executed);
        $this->assertEquals($doc, $result);
    }

    public function testSeamlessAsyncIntegration(): void
    {
        // Test that the same code works in both sync and async contexts
        $doc = [
            'attributeName' => 'seamless_user',
            'attributeValue' => '888',
            'assignments' => ['seamless_exp' => 'seamless_var']
        ];
        
        // Save in sync mode
        $this->service->saveAssignments($doc);
        
        // Test in async context
        $loop = \React\EventLoop\Loop::get();
        $asyncExecuted = false;
        $asyncResult = null;
        
        $loop->futureTick(function () use (&$asyncExecuted, &$asyncResult) {
            // Enable async mode for auto-detection inside event loop context
            \Growthbook\StickyBucketService::enableAsyncMode();
            
            // Same method call, but now returns promise due to event loop context
            $promise = $this->service->getAllAssignments(['seamless_user' => '888']);
            $this->assertInstanceOf(\React\Promise\PromiseInterface::class, $promise);
            
            /** @phpstan-ignore-next-line */
            $promise->then(function ($docs) use (&$asyncExecuted, &$asyncResult) {
                $asyncExecuted = true;
                $asyncResult = $docs;
                
                // Disable async mode after test
                \Growthbook\StickyBucketService::disableAsyncMode();
            });
        });
        
        $loop->run();
        
        $this->assertTrue($asyncExecuted);
        $expectedKey = $this->service->getKey('seamless_user', '888');
        $this->assertArrayHasKey($expectedKey, $asyncResult);
        $this->assertEquals($doc, $asyncResult[$expectedKey]);
    }
}