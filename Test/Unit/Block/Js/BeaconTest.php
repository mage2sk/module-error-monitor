<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Block\Js;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ErrorMonitor\Block\Js\Beacon;
use Panth\ErrorMonitor\Helper\Config;
use PHPUnit\Framework\TestCase;

class BeaconTest extends TestCase
{
    private bool $storeFails = false;

    private array $configCalls = [];

    private array $urlCalls = [];

    private function block(bool $enabled = true, int $sampleRate = 40, bool $secure = true): Beacon
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn('3');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function () use ($store) {
            if ($this->storeFails) {
                throw new \RuntimeException('no store');
            }
            return $store;
        });

        $request = $this->createStub(HttpRequest::class);
        $request->method('isSecure')->willReturn($secure);
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(function ($route = null, $params = null) {
            $this->urlCalls[] = [$route, $params];
            return 'https://shop.test/' . $route . '/?a=1&b=<x>';
        });

        $context = $this->createStub(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        $config = $this->createStub(Config::class);
        $config->method('isJsCaptureEnabled')->willReturnCallback(function ($storeId) use ($enabled): bool {
            $this->configCalls[] = ['enabled', $storeId];
            return $enabled;
        });
        $config->method('getJsSampleRate')->willReturnCallback(function ($storeId) use ($sampleRate): int {
            $this->configCalls[] = ['rate', $storeId];
            return $sampleRate;
        });

        return new Beacon($context, $config);
    }

    public function testCaptureEnabledForCurrentStore(): void
    {
        $this->assertTrue($this->block()->isCaptureEnabled());
        $this->assertFalse($this->block(false)->isCaptureEnabled());
        $this->assertSame([['enabled', 3], ['enabled', 3]], $this->configCalls);
    }

    public function testCaptureDisabledWhenStoreUnavailable(): void
    {
        $this->storeFails = true;
        $this->assertFalse($this->block()->isCaptureEnabled());
    }

    public function testCollectUrlFollowsRequestScheme(): void
    {
        $this->block(true, 40, false)->getCollectUrl();
        $this->block()->getCollectUrl();
        $this->assertSame([
            ['panth_errormonitor/js/collect', ['_secure' => false]],
            ['panth_errormonitor/js/collect', ['_secure' => true]],
        ], $this->urlCalls);
    }

    public function testSampleRate(): void
    {
        $this->assertSame(25, $this->block(true, 25)->getSampleRate());
        $this->assertSame([['rate', 3]], $this->configCalls);
    }

    public function testConfigJsonIsHtmlSafe(): void
    {
        $json = $this->block(true, 10)->getConfigJson();
        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('&', $json);
        $this->assertSame(
            ['url' => 'https://shop.test/panth_errormonitor/js/collect/?a=1&b=<x>', 'sampleRate' => 10, 'maxPerPage' => 10],
            json_decode($json, true)
        );
    }
}
