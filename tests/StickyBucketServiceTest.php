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
        // Test sync behavior - no completion callback
        $doc = [
            'attributeName' => 'user_id',
            'attributeValue' => '123',
            'assignments' => ['experiment1' => 'variation1']
        ];
        
        $this->service->saveAssignments($doc);
        $result = $this->service->getAssignments('user_id', '123');
        
        $this->assertEquals($doc, $result);
    }

    public function testGetAssignmentsAsync(): void
    {
        // Test async behavior - with completion callback
        $doc = [
            'attributeName' => 'user_id', 
            'attributeValue' => '456',
            'assignments' => ['experiment2' => 'variation2']
        ];
        
        $this->service->saveAssignments($doc);
        
        $callbackResult = null;
        $callbackError = null;
        
        $result = $this->service->getAssignments('user_id', '456', function($doc, $error) use (&$callbackResult, &$callbackError) {
            $callbackResult = $doc;
            $callbackError = $error;
        });
        
        // Async version should return null
        $this->assertNull($result);
        // But callback should receive the document
        $this->assertEquals($doc, $callbackResult);
        $this->assertNull($callbackError);
    }

    public function testGetAssignmentsAsyncNotFound(): void
    {
        $callbackResult = null;
        $callbackError = null;
        
        $result = $this->service->getAssignments('user_id', 'nonexistent', function($doc, $error) use (&$callbackResult, &$callbackError) {
            $callbackResult = $doc;
            $callbackError = $error;
        });
        
        $this->assertNull($result);
        $this->assertNull($callbackResult);
        $this->assertNull($callbackError);
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

    public function testSaveAssignmentsAsync(): void
    {
        $doc = [
            'attributeName' => 'device_id',
            'attributeValue' => 'device789',
            'assignments' => ['experiment4' => 'treatment']
        ];
        
        $callbackError = null;
        
        $this->service->saveAssignments($doc, function($error) use (&$callbackError) {
            $callbackError = $error;
        });
        
        // Should save successfully
        $this->assertNull($callbackError);
        
        // Verify it was saved
        $result = $this->service->getAssignments('device_id', 'device789');
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
        $expectedKey1 = $this->service->getKey('user_id', '111');
        $nonExistentKey = $this->service->getKey('session_id', '333');
        $this->assertArrayHasKey($expectedKey1, $result);
        $this->assertArrayNotHasKey($nonExistentKey, $result);
        $this->assertEquals($doc1, $result[$expectedKey1]);
    }

    public function testGetAllAssignmentsAsync(): void
    {
        // Setup test data
        $doc1 = [
            'attributeName' => 'session_id',
            'attributeValue' => 'sess1', 
            'assignments' => ['exp1' => 'treatment']
        ];
        
        $this->service->saveAssignments($doc1);
        
        $callbackResult = null;
        $callbackError = null;
        
        $attributes = ['session_id' => 'sess1'];
        $result = $this->service->getAllAssignments($attributes, function($docs, $error) use (&$callbackResult, &$callbackError) {
            $callbackResult = $docs;
            $callbackError = $error;
        });
        
        // Async version should return empty array
        $this->assertEquals([], $result);
        // But callback should receive the documents
        $expectedKey = $this->service->getKey('session_id', 'sess1');
        $this->assertArrayHasKey($expectedKey, $callbackResult);
        $this->assertEquals($doc1, $callbackResult[$expectedKey]);
        $this->assertNull($callbackError);
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

    public function testAsyncErrorHandling(): void
    {
        // Create a mock service that throws exceptions
        $mockService = new class extends InMemoryStickyBucketService {
            public function getAssignments(string $attributeName, $attributeValue, ?callable $completion = null): ?array
            {
                if ($completion !== null) {
                    try {
                        throw new \Exception('Test error');
                    } catch (\Throwable $error) {
                        $completion(null, $error);
                        return null;
                    }
                }
                return parent::getAssignments($attributeName, $attributeValue);
            }
        };
        
        $callbackResult = null;
        $callbackError = null;
        
        $mockService->getAssignments('user_id', '123', function($doc, $error) use (&$callbackResult, &$callbackError) {
            $callbackResult = $doc;
            $callbackError = $error;
        });
        
        $this->assertNull($callbackResult);
        $this->assertInstanceOf(\Exception::class, $callbackError);
        $this->assertEquals('Test error', $callbackError->getMessage());
    }
}