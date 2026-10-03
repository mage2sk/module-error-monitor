<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Controller\Js;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ErrorMonitor\Controller\Js\Collect;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use Panth\ErrorMonitor\Service\RateLimiter;
use PHPUnit\Framework\TestCase;

class CollectTest extends TestCase
{
    private bool $enabled = true;

    private bool $suspended = false;

    private bool $allowed = true;

    private string $body = '';

    private array $server = ['HTTP_ORIGIN' => 'https://Shop.test'];

    private string $remoteIp = '203.0.113.9';

    private bool $storeFails = false;

    private ?int $responseCode = null;

    private array $headers = [];

    /** @var ErrorPayload[] */
    private array $payloads = [];

    private array $rateCalls = [];

    private array $ipCalls = [];

    private function controller(): Collect
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getContent')->willReturnCallback(fn () => $this->body);
        $request->method('getServer')->willReturnCallback(fn ($key) => $this->server[$key] ?? null);

        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->responseCode = $code;
            return $raw;
        });
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use ($raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $config = $this->createStub(Config::class);
        $config->method('isJsCaptureEnabled')->willReturnCallback(fn () => $this->enabled);
        $config->method('getJsMaxBodyBytes')->willReturn(4096);
        $config->method('getJsRateLimitPerMinute')->willReturn(30);

        $rateLimiter = $this->createStub(RateLimiter::class);
        $rateLimiter->method('allow')->willReturnCallback(function ($ip, $limit): bool {
            $this->rateCalls[] = [$ip, $limit];
            return $this->allowed;
        });

        $recorder = $this->createStub(ErrorRecorder::class);
        $recorder->method('record')->willReturnCallback(function (ErrorPayload $payload): int {
            $this->payloads[] = $payload;
            return 1;
        });

        $anonymizer = $this->createStub(IpAnonymizer::class);
        $anonymizer->method('process')->willReturnCallback(function ($ip, $storeId) {
            $this->ipCalls[] = [$ip, $storeId];
            return $ip === null ? null : 'masked';
        });

        $current = $this->createStub(Store::class);
        $current->method('getId')->willReturn(4);
        $storeA = $this->createStub(Store::class);
        $storeA->method('getBaseUrl')->willReturnCallback(
            static fn ($type = 'link', $secure = null) => $secure ? 'https://secure.shop.test/' : 'http://shop.test/'
        );
        $storeB = $this->createStub(Store::class);
        $storeB->method('getBaseUrl')->willReturn('not a url');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function () use ($current) {
            if ($this->storeFails) {
                throw new \RuntimeException('store not found');
            }
            return $current;
        });
        $storeManager->method('getStores')->willReturn([$storeA, $storeB]);

        $guard = $this->createStub(DeploymentGuard::class);
        $guard->method('isCaptureSuspended')->willReturnCallback(fn () => $this->suspended);

        $remote = $this->createStub(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturnCallback(fn () => $this->remoteIp);

        return new Collect(
            $request,
            $rawFactory,
            $config,
            $rateLimiter,
            $recorder,
            $anonymizer,
            $storeManager,
            $guard,
            $remote
        );
    }

    private function json(array $data): string
    {
        return (string)json_encode($data);
    }

    public function testResponseIsAlwaysNoContentAndUncached(): void
    {
        $this->enabled = false;
        $this->controller()->execute();
        $this->assertSame(204, $this->responseCode);
        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0', $this->headers['Cache-Control']);
        $this->assertSame('no-cache', $this->headers['Pragma']);
        $this->assertSame([], $this->payloads);
    }

    public function testSuspendedCaptureIsIgnored(): void
    {
        $this->suspended = true;
        $this->body = $this->json(['message' => 'x']);
        $this->controller()->execute();
        $this->assertSame([], $this->payloads);
    }

    public function testCrossOriginRequestsAreDropped(): void
    {
        $this->body = $this->json(['message' => 'x']);
        $this->server = ['HTTP_ORIGIN' => 'https://evil.test'];
        $this->controller()->execute();
        $this->server = [];
        $this->controller()->execute();
        $this->server = ['HTTP_ORIGIN' => 'not-a-url'];
        $this->controller()->execute();
        $this->assertSame([], $this->payloads);
        $this->assertSame([], $this->rateCalls);
    }

    public function testRefererIsUsedWhenOriginMissing(): void
    {
        $this->server = ['HTTP_REFERER' => 'https://secure.shop.test/checkout'];
        $this->body = $this->json(['message' => 'boom']);
        $this->controller()->execute();
        $this->assertCount(1, $this->payloads);
        $this->assertSame('https://secure.shop.test/checkout', $this->payloads[0]->url);
        $this->assertSame('https://secure.shop.test/checkout', $this->payloads[0]->referer);
    }

    public function testEmptyOrOversizedBodyIsDropped(): void
    {
        $this->controller()->execute();
        $this->body = $this->json(['message' => str_repeat('a', 5000)]);
        $this->controller()->execute();
        $this->assertSame([], $this->payloads);
        $this->assertSame([], $this->rateCalls);
    }

    public function testRateLimitedClientIsDropped(): void
    {
        $this->allowed = false;
        $this->body = $this->json(['message' => 'boom']);
        $this->controller()->execute();
        $this->assertSame([['203.0.113.9', 30]], $this->rateCalls);
        $this->assertSame([], $this->payloads);
    }

    public function testInvalidPayloadsAreDropped(): void
    {
        foreach (['not json', '"string"', $this->json(['message' => '   ']), $this->json(['message' => ['a']])] as $body) {
            $this->body = $body;
            $this->controller()->execute();
        }
        $this->assertSame([], $this->payloads);
    }

    public function testValidPayloadIsRecorded(): void
    {
        $this->server['HTTP_USER_AGENT'] = 'Browser/2';
        $this->body = $this->json([
            'message' => "  Uncaught TypeError:\x01 x is undefined  ",
            'name' => 'TypeError',
            'source' => 'https://shop.test/static/app.js',
            'stack' => 'at app.js:1:2',
            'kind' => 'unhandledrejection',
            'lineno' => '12',
            'colno' => 7,
            'pageUrl' => 'https://shop.test/cart',
        ]);
        $this->controller()->execute();

        $this->assertCount(1, $this->payloads);
        $payload = $this->payloads[0];
        $this->assertSame('js', $payload->source);
        $this->assertSame('error', $payload->severity);
        $this->assertSame('TypeError', $payload->type);
        $this->assertSame('Uncaught TypeError: x is undefined', $payload->message);
        $this->assertSame('https://shop.test/static/app.js', $payload->file);
        $this->assertSame(12, $payload->line);
        $this->assertSame('at app.js:1:2', $payload->stackTrace);
        $this->assertSame('https://shop.test/cart', $payload->url);
        $this->assertNull($payload->referer);
        $this->assertSame('Browser/2', $payload->userAgent);
        $this->assertSame('masked', $payload->ip);
        $this->assertSame('POST', $payload->httpMethod);
        $this->assertSame(['colno' => 7, 'kind' => 'unhandledrejection', 'origin' => 'storefront-js'], $payload->context);
        $this->assertSame(4, $payload->storeId);
        $this->assertSame([['203.0.113.9', 4]], $this->ipCalls);
    }

    public function testDefaultsForMinimalPayload(): void
    {
        $this->remoteIp = '';
        $this->body = $this->json(['message' => 'boom', 'name' => '']);
        $this->controller()->execute();

        $payload = $this->payloads[0];
        $this->assertSame('Error', $payload->type);
        $this->assertNull($payload->file);
        $this->assertNull($payload->line);
        $this->assertNull($payload->stackTrace);
        $this->assertNull($payload->url);
        $this->assertNull($payload->ip);
        $this->assertSame(['colno' => null, 'kind' => 'error', 'origin' => 'storefront-js'], $payload->context);
        $this->assertSame([[null, 4]], $this->ipCalls);
    }

    public function testLongValuesAreTruncated(): void
    {
        $this->body = $this->json(['message' => str_repeat('m', 2100), 'name' => str_repeat('n', 250)]);
        $this->controller()->execute();
        $this->assertSame(191, strlen($this->payloads[0]->type));
        $this->assertSame(2000, strlen($this->payloads[0]->message));
    }

    public function testFailuresStillReturnNoContent(): void
    {
        $this->storeFails = true;
        $this->body = $this->json(['message' => 'boom']);
        $result = $this->controller()->execute();
        $this->assertInstanceOf(Raw::class, $result);
        $this->assertSame(204, $this->responseCode);
        $this->assertSame([], $this->payloads);
    }

    public function testCsrfValidationIsBypassed(): void
    {
        $controller = $this->controller();
        $request = $this->createStub(RequestInterface::class);
        $this->assertTrue($controller->validateForCsrf($request));
        $this->assertNull($controller->createCsrfValidationException($request));
    }
}
