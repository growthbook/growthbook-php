<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Growthbook\Growthbook;
use Growthbook\RequestBodyForRemoteEval;
use Growthbook\FeatureFetchException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

class RemoteEvaluationTest extends TestCase
{
    private Growthbook $growthbook;
    /** @var ClientInterface&MockObject */
    private $mockHttpClient;
    /** @var RequestFactoryInterface&MockObject */
    private $mockRequestFactory;
    /** @var CacheInterface&MockObject */
    private $mockCache;
    /** @var ResponseInterface&MockObject */
    private $mockResponse;
    /** @var StreamInterface&MockObject */
    private $mockStream;
    /** @var RequestInterface&MockObject */
    private $mockRequest;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(ClientInterface::class);
        $this->mockRequestFactory = $this->createMock(RequestFactoryInterface::class);
        $this->mockCache = $this->createMock(CacheInterface::class);
        $this->mockResponse = $this->createMock(ResponseInterface::class);
        $this->mockStream = $this->createMock(StreamInterface::class);
        $this->mockRequest = $this->createMock(RequestInterface::class);

        $this->growthbook = new Growthbook();
        $this->growthbook->withHttpClient($this->mockHttpClient, $this->mockRequestFactory);
        $this->growthbook->setRemoteEvalEndpoint('test-client-key');
    }

    public function testSetRemoteEvalEndpoint(): void
    {
        $this->growthbook->setRemoteEvalEndpoint('my-client-key');
        
        // We can't directly access the private property, so we test by attempting a fetch
        // which will fail with a specific error if the endpoint is not set
        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);
        
        $this->expectException(FeatureFetchException::class);
        $this->growthbook->fetchForRemoteEval($requestBody);
    }

    public function testSetRemoteEvalEndpointWithCustomHost(): void
    {
        $this->growthbook->setRemoteEvalEndpoint('my-client-key', 'https://custom.growthbook.io');
        
        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);
        
        $this->expectException(FeatureFetchException::class);
        $this->growthbook->fetchForRemoteEval($requestBody);
    }

    public function testFetchForRemoteEvalThrowsExceptionWhenEndpointNotSet(): void
    {
        $growthbook = new Growthbook();
        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('remote eval features endpoint cannot be null');
        
        $growthbook->fetchForRemoteEval($requestBody);
    }

    public function testFetchForRemoteEvalThrowsExceptionWhenHttpClientNotSet(): void
    {
        $growthbook = new Growthbook();
        $growthbook->setRemoteEvalEndpoint('test-client-key');
        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);

        $this->expectException(FeatureFetchException::class);
        $this->expectExceptionMessage('HTTP client is not configured');
        
        $growthbook->fetchForRemoteEval($requestBody);
    }

    public function testFetchForRemoteEvalSuccessfulResponse(): void
    {
        $responseBody = json_encode([
            'features' => ['feature1' => ['defaultValue' => true]],
            'savedGroups' => ['group1' => ['id' => 'group1']]
        ]);
        if ($responseBody === false) {
            $this->fail('Failed to encode response body');
        }

        $this->setupSuccessfulHttpMock($responseBody);

        $requestBody = new RequestBodyForRemoteEval(
            ['user_id' => '123'],
            null,
            null,
            'https://example.com'
        );

        $this->growthbook->fetchForRemoteEval($requestBody);

        // Verify features were loaded
        $features = $this->growthbook->getFeatures();
        $this->assertArrayHasKey('feature1', $features);
        $this->assertEquals(true, $features['feature1']->defaultValue);
    }

    public function testFetchForRemoteEvalWith500ErrorAndNoCache(): void
    {
        $this->setupFailedHttpMock(500, 'Internal Server Error');

        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);

        $this->expectException(FeatureFetchException::class);
        $this->expectExceptionMessage('Failed to fetch data from server and cache is disabled');

        $this->growthbook->fetchForRemoteEval($requestBody);
    }

    public function testFetchForRemoteEvalWith500ErrorAndCacheEnabled(): void
    {
        $this->growthbook->withCache($this->mockCache);
        
        $this->setupFailedHttpMock(500, 'Internal Server Error');

        $cachedData = json_encode([
            'features' => ['cached_feature' => ['defaultValue' => 'cached']],
            'savedGroups' => []
        ]);

        $this->mockCache
            ->expects($this->once())
            ->method('get')
            ->willReturn($cachedData);

        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);

        $this->growthbook->fetchForRemoteEval($requestBody);

        // Verify cached features were loaded
        $features = $this->growthbook->getFeatures();
        $this->assertArrayHasKey('cached_feature', $features);
        $this->assertEquals('cached', $features['cached_feature']->defaultValue);
    }

    public function testFetchForRemoteEvalWith500ErrorAndEmptyCache(): void
    {
        $this->growthbook->withCache($this->mockCache);
        
        $this->setupFailedHttpMock(500, 'Internal Server Error');

        $this->mockCache
            ->expects($this->once())
            ->method('get')
            ->willReturn(''); // Empty cache

        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);

        $this->expectException(FeatureFetchException::class);
        $this->expectExceptionMessage('Failed to fetch data from cache');

        $this->growthbook->fetchForRemoteEval($requestBody);
    }

    public function testFetchForRemoteEvalCachesSuccessfulResponse(): void
    {
        $this->growthbook->withCache($this->mockCache);

        $responseBody = json_encode([
            'features' => ['feature1' => ['defaultValue' => true]],
            'savedGroups' => ['group1' => ['id' => 'group1']]
        ]);
        if ($responseBody === false) {
            $this->fail('Failed to encode response body');
        }

        $this->setupSuccessfulHttpMock($responseBody);

        $this->mockCache
            ->expects($this->once())
            ->method('set')
            ->with(
                $this->isType('string'), // cache key
                $responseBody,
                60 // default TTL
            );

        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);

        $this->growthbook->fetchForRemoteEval($requestBody);
    }

    public function testFetchForRemoteEvalWithNetworkException(): void
    {
        $this->mockRequestFactory
            ->expects($this->once())
            ->method('createRequest')
            ->willReturn($this->mockRequest);

        $this->mockRequest
            ->expects($this->any())
            ->method('withHeader')
            ->willReturnSelf();

        $this->mockRequest
            ->expects($this->any())
            ->method('withBody')
            ->willReturnSelf();

        $this->mockHttpClient
            ->expects($this->once())
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Network error'));

        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);

        $this->expectException(FeatureFetchException::class);
        $this->expectExceptionMessage('Network error');

        $this->growthbook->fetchForRemoteEval($requestBody);
    }

    public function testFetchForRemoteEvalWithInvalidJsonResponse(): void
    {
        $this->setupSuccessfulHttpMock('invalid json');

        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);

        $this->expectException(FeatureFetchException::class);
        $this->expectExceptionMessage('Invalid JSON response from remote evaluation');

        $this->growthbook->fetchForRemoteEval($requestBody);
    }

    public function testRequestBodyForRemoteEvalJsonSerialization(): void
    {
        $requestBody = new RequestBodyForRemoteEval(
            ['user_id' => '123', 'premium' => true],
            [['feature1', 'variation1']],
            ['experiment1' => 1],
            'https://example.com/page'
        );

        $json = json_encode($requestBody);
        if ($json === false) {
            $this->fail('Failed to encode request body');
        }
        $decoded = json_decode($json, true);

        $this->assertEquals(['user_id' => '123', 'premium' => true], $decoded['attributes']);
        $this->assertEquals([['feature1', 'variation1']], $decoded['forcedFeatures']);
        $this->assertEquals(['experiment1' => 1], $decoded['forcedVariations']);
        $this->assertEquals('https://example.com/page', $decoded['url']);
    }

    public function testRequestBodyForRemoteEvalWithNullValues(): void
    {
        $requestBody = new RequestBodyForRemoteEval();

        $json = json_encode($requestBody);
        if ($json === false) {
            $this->fail('Failed to encode request body');
        }
        $decoded = json_decode($json, true);

        // Should only include non-null values
        $this->assertEquals([], $decoded);
    }

    public function testCacheIsEnabledInConstructor(): void
    {
        $growthbook = new Growthbook([
            'cache' => $this->mockCache
        ]);
        $growthbook->withHttpClient($this->mockHttpClient, $this->mockRequestFactory);
        $growthbook->setRemoteEvalEndpoint('test-client-key');

        $this->setupFailedHttpMock(500, 'Server Error');

        $cachedData = json_encode(['features' => [], 'savedGroups' => []]);
        $this->mockCache
            ->expects($this->once())
            ->method('get')
            ->willReturn($cachedData);

        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);

        // Should not throw exception because cache is enabled and has data
        $growthbook->fetchForRemoteEval($requestBody);
        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    public function testCacheIsEnabledWithWithCacheMethod(): void
    {
        $this->growthbook->withCache($this->mockCache);

        $this->setupFailedHttpMock(500, 'Server Error');

        $cachedData = json_encode(['features' => [], 'savedGroups' => []]);
        $this->mockCache
            ->expects($this->once())
            ->method('get')
            ->willReturn($cachedData);

        $requestBody = new RequestBodyForRemoteEval(['user_id' => '123']);

        // Should not throw exception because cache is enabled and has data
        $this->growthbook->fetchForRemoteEval($requestBody);
        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    private function setupSuccessfulHttpMock(string $responseBody): void
    {
        $this->mockResponse
            ->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);

        $this->mockResponse
            ->expects($this->once())
            ->method('getBody')
            ->willReturn($this->mockStream);

        $this->mockStream
            ->expects($this->once())
            ->method('getContents')
            ->willReturn($responseBody);

        $mockStreamFactory = $this->createMock(StreamFactoryInterface::class);
        $mockBodyStream = $this->createMock(StreamInterface::class);
        
        $mockStreamFactory
            ->expects($this->any())
            ->method('createStream')
            ->willReturn($mockBodyStream);

        $this->mockRequestFactory
            ->expects($this->once())
            ->method('createRequest')
            ->willReturn($this->mockRequest);

        $this->mockRequest
            ->expects($this->any())
            ->method('withHeader')
            ->willReturnSelf();

        $this->mockRequest
            ->expects($this->any())
            ->method('withBody')
            ->willReturnSelf();

        $this->mockHttpClient
            ->expects($this->once())
            ->method('sendRequest')
            ->willReturn($this->mockResponse);
    }

    private function setupFailedHttpMock(int $statusCode, string $reasonPhrase): void
    {
        $this->mockResponse
            ->expects($this->any())
            ->method('getStatusCode')
            ->willReturn($statusCode);

        $this->mockResponse
            ->expects($this->any())
            ->method('getReasonPhrase')
            ->willReturn($reasonPhrase);

        $this->mockResponse
            ->expects($this->any())
            ->method('getBody')
            ->willReturn($this->mockStream);

        $this->mockStream
            ->expects($this->any())
            ->method('getContents')
            ->willReturn('Error response body');

        $this->mockRequestFactory
            ->expects($this->once())
            ->method('createRequest')
            ->willReturn($this->mockRequest);

        $this->mockRequest
            ->expects($this->any())
            ->method('withHeader')
            ->willReturnSelf();

        $this->mockRequest
            ->expects($this->any())
            ->method('withBody')
            ->willReturnSelf();

        $this->mockHttpClient
            ->expects($this->once())
            ->method('sendRequest')
            ->willReturn($this->mockResponse);
    }
}