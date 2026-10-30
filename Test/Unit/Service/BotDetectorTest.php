<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\ErrorMonitor\Service\BotDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BotDetectorTest extends TestCase
{
    private ?string $patterns = null;

    private bool $ignore = true;

    private function detector(): BotDetector
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            fn ($path) => $path === BotDetector::XML_BOT_PATTERNS ? $this->patterns : null
        );
        $scope->method('isSetFlag')->willReturnCallback(
            fn ($path) => $path === BotDetector::XML_IGNORE_BOTS && $this->ignore
        );
        return new BotDetector($scope);
    }

    public static function botAgents(): array
    {
        return [
            ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            ['Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm#07)'],
            ['Mozilla/5.0 (compatible; DotBot/1.2; +https://opensiteexplorer.org/dotbot)'],
            ['Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Baiduspider-render/2.0'],
            ['Mozilla/5.0 (compatible; SEBot-WA/1.0; +https://example.com)'],
            ['MagentoProbe/1.0'],
            ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36'],
            ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            ['Mozilla/5.0 (compatible; YandexBot/3.0)'],
            ['Mozilla/5.0 (Linux; Android 7.0;) AppleWebKit/537.36 (KHTML, like Gecko) PetalBot'],
        ];
    }

    /**
     * @dataProvider botAgents
     */
    #[DataProvider('botAgents')]
    public function testDefaultListMatchesKnownBots(string $agent): void
    {
        $this->assertTrue($this->detector()->isBot($agent));
    }

    public function testRealBrowsersAreNotBots(): void
    {
        $detector = $this->detector();
        $this->assertFalse($detector->isBot(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'
        ));
        $this->assertFalse($detector->isBot(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0'
            . ' Mobile/15E148 Safari/604.1'
        ));
        $this->assertFalse($detector->isBot(null));
        $this->assertFalse($detector->isBot('   '));
    }

    public function testConfiguredRegexAndInvalidLinesAreHandled(): void
    {
        $this->patterns = "^Acme-Monitor/\\d+\n(unclosed\n  \nwatchdog";
        $detector = $this->detector();
        $this->assertTrue($detector->isBot('Acme-Monitor/3 (+https://acme.test)'));
        $this->assertTrue($detector->isBot('Mozilla WatchDog'));
        $this->assertFalse($detector->isBot('Mozilla/5.0 Acme-Monitor/3'));
        $this->assertFalse($detector->isBot('Mozilla/5.0 (compatible; bingbot/2.0)'));
    }

    public function testEmptyListDisablesDetection(): void
    {
        $this->patterns = '';
        $this->assertFalse($this->detector()->isBot('Googlebot/2.1'));
        $this->assertSame([], $this->detector()->patterns());
    }

    public function testIgnoreFlag(): void
    {
        $this->assertTrue($this->detector()->shouldIgnore());
        $this->ignore = false;
        $this->assertFalse($this->detector()->shouldIgnore());
    }

    public function testStaticMatcher(): void
    {
        $this->assertTrue(BotDetector::matches('Slurp', BotDetector::DEFAULT_PATTERNS));
        $this->assertFalse(BotDetector::matches('Slurp', []));
        $this->assertNull(BotDetector::compile(['(']));
    }
}
