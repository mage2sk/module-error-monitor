<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\ErrorMonitor\Service\NoiseFilter;
use PHPUnit\Framework\TestCase;

class NoiseFilterTest extends TestCase
{
    private bool $enabled = true;

    private array $values = [];

    private function filter(): NoiseFilter
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(
            fn ($path) => $path === NoiseFilter::XML_ENABLED && $this->enabled
        );
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->values[$path] ?? null);
        return new NoiseFilter($scope);
    }

    public static function noise(): array
    {
        $customerData = 'https://shop.test/static/version1785394171/frontend/V/t/en_US/Magento_Customer/js/customer-data.min.js';
        return [
            'outlook safe links' => ['Object Not Found Matching Id:2, MethodName:update, ParamCount:4', null, null],
            'chrome storage remove' => [
                "Uncaught TypeError: Cannot read properties of undefined (reading 'remove')",
                $customerData,
                null,
            ],
            'safari storage remove' => [
                "TypeError: undefined is not an object (evaluating 'storage.remove')",
                $customerData,
                null,
            ],
            'delete tmp' => [
                'TypeError: Cannot convert undefined or null to object delete tmp[a[i]]',
                null,
                'at f (https://shop.test/static/frontend/V/t/en_US/jquery/jquery.storageapi.min.js:1:2)',
            ],
            'quota' => ['QuotaExceededError: The quota has been exceeded.', $customerData, null],
            'security error storage' => [
                "SecurityError: Failed to read the 'localStorage' property from 'Window': Access is denied",
                $customerData,
                null,
            ],
            'security error js.storage' => [
                'SecurityError: The operation is insecure.',
                'https://shop.test/static/frontend/V/t/en_US/js/jquery/jquery.storageapi.min.js',
                null,
            ],
            'script error without stack' => ['Script error.', null, ''],
            'chrome extension file' => ['boom', 'chrome-extension://abcdef/content.js', null],
            'moz extension in stack' => ['boom', null, "at f (moz-extension://123/inject.js:1:2)"],
        ];
    }

    /**
     * @dataProvider noise
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('noise')]
    public function testDefaultNoiseIsIgnored(string $message, ?string $file, ?string $stack): void
    {
        $this->assertTrue($this->filter()->isIgnored($message, $file, 'Error', $stack));
    }

    public function testRealErrorsAreKept(): void
    {
        $filter = $this->filter();
        $this->assertFalse($filter->isIgnored(
            "TypeError: Cannot read properties of undefined (reading 'remove')",
            'https://shop.test/static/frontend/V/t/en_US/Acme_Cart/js/cart.min.js',
            'TypeError'
        ));
        $this->assertFalse($filter->isIgnored('Script error.', null, 'Error', 'at x (app.js:1:1)'));
        $this->assertFalse($filter->isIgnored('SecurityError: blocked frame', null, 'Error'));
        $this->assertFalse($filter->isIgnored(
            "SecurityError: Failed to read the 'localStorage' property from 'Window': Access is denied",
            'https://shop.test/static/frontend/V/t/en_US/Acme_Cart/js/cart.js',
            'Error'
        ));
        $this->assertFalse($filter->isIgnored('Order save failed', '/var/www/app/code/A/B.php', 'Exception'));
    }

    public function testSwitchOffKeepsEverything(): void
    {
        $this->enabled = false;
        $this->assertFalse($this->filter()->isIgnored('Object Not Found Matching Id:2, MethodName:update, ParamCount:4', null));
    }

    public function testCustomRulesAndInvalidRules(): void
    {
        $this->values[NoiseFilter::XML_PATTERNS] = "file:acme-widget\\.js && message:^boom$\n(broken\n";
        $filter = $this->filter();
        $this->assertTrue($filter->isIgnored('boom', 'https://cdn.test/acme-widget.js'));
        $this->assertFalse($filter->isIgnored('boom', 'https://cdn.test/other.js'));
        $this->assertFalse($filter->isIgnored('Object Not Found Matching Id:2, MethodName:update, ParamCount:4', null));
        $this->assertCount(1, NoiseFilter::parseRules(["file:a && message:b", '(broken', 'stack:', '']));
    }

    public function testIgnoreListStillApplies(): void
    {
        $this->enabled = false;
        $this->values[NoiseFilter::XML_IGNORE_PATTERNS] = "ChunkLoadError\nVendor_Noisy";
        $filter = $this->filter();
        $this->assertTrue($filter->isIgnored('x', 'app/code/Vendor_Noisy/a.js'));
        $this->assertTrue($filter->isIgnored('ChunkLoadError: Loading chunk 7 failed', null));
        $this->assertFalse($filter->isIgnored('Fine', null));
    }
}
