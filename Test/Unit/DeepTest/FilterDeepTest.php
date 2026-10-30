<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\DeepTest;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Logger\DbHandler;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use Panth\ErrorMonitor\Service\BotDetector;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use Panth\ErrorMonitor\Service\NoiseFilter;
use Panth\ErrorMonitor\Service\PathMapper;
use Panth\ErrorMonitor\Service\TimeFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use Panth\ErrorMonitor\Test\Unit\MonologRecordTrait;
use PHPUnit\Framework\TestCase;

/**
 * Deep test chunk 5: bot/crawler filter (#3) and default noise/ignore patterns (#6).
 * Defaults are read from the shipped etc/config.xml so the tests exercise what a
 * fresh or upgraded install really gets without any core_config_data rows.
 */
class FilterDeepTest extends TestCase
{
    use MonologRecordTrait;

    /** @var array<string, mixed> config.xml defaults, flattened to paths */
    private static array $defaults = [];

    /** @var array<string, mixed> per-test overrides (null = use default) */
    private array $overrides = [];

    private array $server = [];

    /** @var ErrorPayload[] */
    private array $payloads = [];

    public static function setUpBeforeClass(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/config.xml');
        $root = $xml->default->panth_errormonitor;
        foreach ($root->children() as $group) {
            foreach ($group->children() as $field) {
                self::$defaults['panth_errormonitor/' . $group->getName() . '/' . $field->getName()] = (string)$field;
            }
        }
    }

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        foreach (['REQUEST_URI', 'HTTP_REFERER', 'HTTP_USER_AGENT', 'REMOTE_ADDR', 'REQUEST_METHOD'] as $key) {
            unset($_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function value(string $path): mixed
    {
        return array_key_exists($path, $this->overrides) ? $this->overrides[$path] : (self::$defaults[$path] ?? null);
    }

    private function scope(): ScopeConfigInterface
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->value((string)$path));
        $scope->method('isSetFlag')->willReturnCallback(fn ($path) => (bool)(int)$this->value((string)$path));
        return $scope;
    }

    private function bots(): BotDetector
    {
        return new BotDetector($this->scope());
    }

    private function noise(): NoiseFilter
    {
        return new NoiseFilter($this->scope());
    }

    // ------------------------------------------------------------------ defaults

    public function testShippedDefaultsAreYesAndMatchTheRequiredList(): void
    {
        $this->assertSame('1', self::$defaults['panth_errormonitor/filters/ignore_bots']);
        $this->assertSame('1', self::$defaults['panth_errormonitor/filters/noise_enabled']);
        $this->assertSame('1', self::$defaults['panth_errormonitor/filters/email_exclude_bot_only']);
        $required = ['bot', 'crawl', 'spider', 'slurp', 'bingbot', 'baiduspider', 'sogou', 'dotbot', 'ahrefs',
            'semrush', 'yandex', 'petal', 'headless', 'probe', 'preview',
            'facebookexternalhit', 'facebookcatalog', 'Google-Read-Aloud'];
        $this->assertSame(
            $required,
            BotDetector::splitLines(self::$defaults['panth_errormonitor/filters/bot_user_agents'])
        );
        $this->assertSame(BotDetector::DEFAULT_PATTERNS, $required, 'PHP fallback list must equal config.xml');
        $this->assertSame(
            NoiseFilter::DEFAULT_RULES,
            BotDetector::splitLines(self::$defaults['panth_errormonitor/filters/noise_patterns']),
            'PHP fallback rules must equal config.xml (CDATA) rules'
        );
        // every shipped noise rule must compile (an invalid one would be silently dropped)
        $this->assertCount(count(NoiseFilter::DEFAULT_RULES), NoiseFilter::parseRules(NoiseFilter::DEFAULT_RULES));
    }

    // ------------------------------------------------------------------ bot UAs

    public static function botAgents(): array
    {
        return [
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'bingbot-mobile' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.1938.76 Mobile Safari/537.36 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'Googlebot-smartphone' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.6668.70 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'Baiduspider-render' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 9_1 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Version/9.0 Mobile/13B143 Safari/601.1 (compatible; Baiduspider-render/2.0; +http://www.baidu.com/search/spider.html)'],
            'Sogou' => ['Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm#07)'],
            'DotBot' => ['Mozilla/5.0 (compatible; DotBot/1.2; +https://opensiteexplorer.org/dotbot; help@moz.com)'],
            'AhrefsBot' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            'SemrushBot' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)'],
            'YandexBot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'],
            'PetalBot' => ['Mozilla/5.0 (Linux; Android 7.0;) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)'],
            'SEBot-WA' => ['Mozilla/5.0 (compatible; SEBot-WA/1.0; +https://www.example.com/sebot)'],
            'MagentoProbe' => ['MagentoProbe/1.0'],
            'HeadlessChrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0.0.0 Safari/537.36'],
            'Google-Read-Aloud' => ['Mozilla/5.0 (Linux; Android 7.0; SM-G930V Build/NRD90M) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/59.0.3071.125 Mobile Safari/537.36 (compatible; Google-Read-Aloud; +https://support.google.com/webmasters/answer/1061943)'],
            'facebookexternalhit' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'facebookcatalog' => ['facebookcatalog/1.0'],
            'LinkedInBot' => ['LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)'],
            'Slurp' => ['Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)'],
        ];
    }

    /**
     * @dataProvider botAgents
     */
    #[DataProvider('botAgents')]
    public function testBotIsDetected(string $ua): void
    {
        $this->assertTrue($this->bots()->isBot($ua), 'not detected as bot: ' . $ua);
    }

    public static function humanAgents(): array
    {
        return [
            'Chrome-Win' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'],
            'Edge-Win' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0'],
            'Firefox-Win' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0'],
            'Safari-Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15'],
            'Chrome-Android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'],
            'Safari-iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'],
            'CriOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.90 Mobile/15E148 Safari/604.1'],
            'FxiOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/131.0 Mobile/15E148 Safari/605.1.15'],
            'EdgiOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 EdgiOS/130.0.2849.80 Mobile/15E148 Safari/605.1.15'],
            'Firefox-Android' => ['Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0'],
            'Samsung' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36'],
            'Instagram-iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0.3.29.91 (iPhone14,2; iOS 17_4; en_GB; en; scale=3.00; 1170x2532; 600000000)'],
            'Instagram-Android' => ['Mozilla/5.0 (Linux; Android 13; SM-A536B Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.6099.144 Mobile Safari/537.36 Instagram 312.1.0.34.111 Android (33/13; 450dpi; 1080x2177; samsung; SM-A536B; a53x; s5e8825; en_GB; 548323757)'],
            'FB-iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/460.0.0.38.104;FBBV/600000000;FBDV/iPhone14,2;FBMD/iPhone;FBSN/iOS;FBSV/17.4;FBSS/3;FBID/phone;FBLC/en_GB;FBOP/5;FBRV/0]'],
            'FB-Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240805.005; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/127.0.6533.103 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/478.0.0.41.86;]'],
            'iPad-desktop-mode' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15'],
            'iPad-legacy' => ['Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1'],
            'YaBrowser' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 YaBrowser/24.4.0.0 Safari/537.36'],
            // false-positive traps: "bot" inside a device/app name
            'Cubot-phone' => ['Mozilla/5.0 (Linux; Android 11; CUBOT_X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'],
            'Cubot-KingKong' => ['Mozilla/5.0 (Linux; Android 10; CUBOT KINGKONG 5 Pro) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Mobile Safari/537.36'],
            'robot-app-webview' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 RobotVacuumApp/2.1'],
            'Abbott-app-webview' => ['Mozilla/5.0 (Linux; Android 14; Pixel 7; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/128.0.0.0 Mobile Safari/537.36 AbbottLibreLink/2.10'],
        ];
    }

    /**
     * @dataProvider humanAgents
     */
    #[DataProvider('humanAgents')]
    public function testHumanIsNotFlagged(string $ua): void
    {
        $this->assertFalse($this->bots()->isBot($ua), 'false positive bot: ' . $ua);
    }

    // ------------------------------------------------------------------ regex safety

    public function testCaseInsensitiveAndWhitespaceAndEmptyLines(): void
    {
        $this->overrides['panth_errormonitor/filters/bot_user_agents'] = "  \r\n\tBINGBOT  \r\n\r\n   \nacme-monitor\r";
        $d = $this->bots();
        $this->assertSame(['BINGBOT', 'acme-monitor'], $d->patterns());
        $this->assertTrue($d->isBot('mozilla/5.0 (compatible; bingbot/2.0)'));
        $this->assertTrue($d->isBot('ACME-MONITOR/1'));
        $this->assertFalse($d->isBot('Mozilla/5.0 Chrome/130'));
    }

    public function testInvalidRegexDoesNotFatalAndOtherPatternsStillApply(): void
    {
        $this->overrides['panth_errormonitor/filters/bot_user_agents'] = "(unclosed\n[a-\n*bad\nbingbot\n\\";
        $d = $this->bots();
        $this->assertTrue($d->isBot('Mozilla/5.0 (compatible; bingbot/2.0)'));
        $this->assertFalse($d->isBot('Mozilla/5.0 Chrome/130'));
    }

    /** Admin saves only broken lines: the filter silently stops detecting anything (no fallback, no log). */
    public function testAllInvalidPatternsFallBackToDefaults(): void
    {
        $this->overrides['panth_errormonitor/filters/bot_user_agents'] = "(unclosed\n[a-";
        $this->assertTrue(
            $this->bots()->isBot('Mozilla/5.0 (compatible; bingbot/2.0)'),
            'all-invalid list disables bot detection silently instead of falling back to the defaults'
        );
    }

    public function testTildeDelimiterInsidePatternIsEscaped(): void
    {
        $this->assertTrue(BotDetector::matches('SemrushBot/7~bl', ['Bot/\d+~bl']));
        $this->assertTrue(BotDetector::matches('x#y', ['x#y']));
        $this->assertTrue(BotDetector::matches('Foo/1', ['^Foo/\d$']));
    }

    /** "/googlebot/i" looks like a regex to an admin but is matched as a literal string. */
    public function testSlashDelimitedPatternIsLiteral(): void
    {
        $this->assertFalse(BotDetector::matches('Googlebot/2.1', ['/googlebot/i']));
    }

    /** Patterns are OR-ed into one regex, so a back-reference in a later line points at an earlier line's group. */
    public function testBackReferenceInSecondPatternKeepsItsOwnMeaning(): void
    {
        $this->assertTrue(BotDetector::matches('barbar', ['(foo)x', '(bar)\1']));
    }

    public function testCatastrophicBotPatternDoesNotHang(): void
    {
        $this->overrides['panth_errormonitor/filters/bot_user_agents'] = "(a+)+\$\n(.*a){20}";
        $d = $this->bots();
        $start = microtime(true);
        $result = $d->isBot(str_repeat('a', 8000) . '!');
        $elapsed = microtime(true) - $start;
        $this->assertIsBool($result);
        $this->assertLessThan(2.0, $elapsed, 'ReDoS: bot regex took ' . round($elapsed, 2) . 's');
    }

    public function testCatastrophicNoiseRuleDoesNotHangAndInvalidRuleIsSkipped(): void
    {
        $this->overrides['panth_errormonitor/filters/noise_patterns'] = "(x+x+)+y\n(\nmessage: && file:x\nCustomNoise";
        $n = $this->noise();
        $start = microtime(true);
        $this->assertFalse($n->isIgnored(str_repeat('x', 4000), null));
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertTrue($n->isIgnored('something customnoise here', null));
        $this->assertCount(2, NoiseFilter::parseRules(BotDetector::splitLines(
            $this->overrides['panth_errormonitor/filters/noise_patterns']
        )));
    }

    public function testEmptyBotListDisablesDetectionAsDocumented(): void
    {
        $this->overrides['panth_errormonitor/filters/bot_user_agents'] = '';
        $this->assertFalse($this->bots()->isBot('Googlebot/2.1'));
    }

    // ------------------------------------------------------------------ noise rules (defaults)

    public function testOutlookSafeLinksScanner(): void
    {
        $n = $this->noise();
        $this->assertTrue($n->isIgnored('Object Not Found Matching Id:2, MethodName:update, ParamCount:4', null));
        $this->assertTrue($n->isIgnored('Uncaught Object Not Found Matching Id:13, MethodName:update, ParamCount:4', 'https://shop.test/'));
        $this->assertFalse($n->isIgnored('Object Not Found', null));
    }

    public function testStorageErrorsFromCustomerDataAreIgnored(): void
    {
        $n = $this->noise();
        $cd = 'https://shop.test/static/frontend/Hyva/default/en_US/Magento_Customer/js/customer-data.js';
        $st = 'https://shop.test/static/frontend/Magento/luma/en_US/jquery/jquery.storageapi.min.js';
        $this->assertTrue($n->isIgnored("Uncaught TypeError: Cannot read properties of undefined (reading 'remove')", $cd));
        $this->assertTrue($n->isIgnored("TypeError: undefined is not an object (evaluating 'storage.remove')", $cd));
        $this->assertTrue($n->isIgnored("TypeError: Cannot convert undefined or null to object (delete tmp[a[i]])", $st));
        $this->assertTrue($n->isIgnored('QuotaExceededError: The quota has been exceeded.', $cd));
        $this->assertTrue($n->isIgnored("SecurityError: Failed to read the 'localStorage' property from 'Window'", $cd));
        $this->assertFalse($n->isIgnored("SecurityError: Failed to read the 'localStorage' property from 'Window'", null));
        $this->assertTrue($n->isIgnored('SecurityError: The operation is insecure.', 'https://shop.test/static/js.storage.js'));
    }

    public function testRemoveTypeErrorOutsideStorageLibsIsKept(): void
    {
        $this->assertFalse($this->noise()->isIgnored(
            "Uncaught TypeError: Cannot read properties of undefined (reading 'remove')",
            'https://shop.test/static/frontend/Vendor/theme/en_US/Vendor_Cart/js/mini-cart.js'
        ));
    }

    public static function storageBugsOutsideStorageLibs(): array
    {
        $custom = 'https://shop.test/static/frontend/Vendor/theme/en_US/Vendor_Checkout/js/save-draft.js';
        return [
            'QuotaExceededError from own code' => ['QuotaExceededError: Failed to execute \'setItem\' on \'Storage\'', $custom],
            'localStorage.removeItem misuse' => ['TypeError: window.localStorage.removeItem is not a function', $custom],
            'own storage.remove call' => ["TypeError: undefined is not an object (evaluating 'this.storage.remove')", $custom],
        ];
    }

    /**
     * Storage rules only apply when the file or stack is customer-data, js.storage or storageapi.
     *
     * @dataProvider storageBugsOutsideStorageLibs
     */
    #[DataProvider('storageBugsOutsideStorageLibs')]
    public function testStorageErrorsOutsideCustomerDataAreNotHidden(string $message, string $file): void
    {
        $this->assertFalse($this->noise()->isIgnored($message, $file), 'real bug hidden: ' . $message);
    }

    public function testScriptErrorWithoutStackIsIgnored(): void
    {
        $n = $this->noise();
        $this->assertTrue($n->isIgnored('Script error.', null, 'Error', null));
        $this->assertTrue($n->isIgnored('Uncaught Script error.', null, 'Error', '   '));
        // with the rule alone (independent of general/ignore_patterns)
        $rules = NoiseFilter::parseRules(NoiseFilter::DEFAULT_RULES);
        $this->assertTrue(NoiseFilter::matchRules($rules, 'Script error.', null, ''));
        $this->assertFalse(NoiseFilter::matchRules($rules, 'Script error.', null, "at foo (https://shop.test/app.js:1:2)"));
    }

    /** The owner asked to drop "Script error." only WITHOUT a stack; the general ignore list drops it always. */
    public function testScriptErrorWithStackIsKeptByDefault(): void
    {
        $this->assertFalse($this->noise()->isIgnored(
            'Script error.',
            'https://shop.test/static/app.js',
            'Error',
            "Error: Script error.\n    at render (https://shop.test/static/app.js:10:5)"
        ));
    }

    public function testBrowserExtensionInFileOrStack(): void
    {
        $n = $this->noise();
        $this->assertTrue($n->isIgnored('TypeError: x is null', 'chrome-extension://abcdefgh/content.js'));
        $this->assertTrue($n->isIgnored('TypeError: x is null', null, '', "at f (moz-extension://1234-5678/inject.js:1:1)"));
        $this->assertTrue($n->isIgnored('TypeError: x is null', 'safari-extension://com.foo/script.js'));
        $this->assertTrue($n->isIgnored('TypeError: x is null', 'safari-web-extension://ABC/script.js'));
        $this->assertFalse($n->isIgnored('TypeError: x is null', 'https://shop.test/static/app.js', '', 'at f (https://shop.test/static/app.js:1:1)'));
    }

    public function testNoiseSwitchOffKeepsEverythingButTheExplicitIgnoreList(): void
    {
        $this->overrides['panth_errormonitor/filters/noise_enabled'] = '0';
        $n = $this->noise();
        $this->assertFalse($n->isIgnored('Object Not Found Matching Id:2, MethodName:update, ParamCount:4', null));
        $this->assertFalse($n->isIgnored('TypeError: x', 'chrome-extension://abc/c.js'));
        // general/ignore_patterns still applies
        $this->assertTrue($n->isIgnored('ResizeObserver loop limit exceeded', null));
    }

    public function testLegacyIgnorePathIsOnlyReadWhenGeneralIsEmpty(): void
    {
        // config.xml ships a non-empty general/ignore_patterns default, so an install that only ever
        // saved the legacy js_capture/ignore_patterns relies on the MigrateIgnorePatternsToGeneral patch.
        $this->overrides['panth_errormonitor/js_capture/ignore_patterns'] = 'MyLegacyNoise';
        $this->assertFalse($this->noise()->isIgnored('MyLegacyNoise happened', null));
        $this->overrides['panth_errormonitor/general/ignore_patterns'] = '';
        $this->assertTrue($this->noise()->isIgnored('MyLegacyNoise happened', null));
    }

    // ------------------------------------------------------------------ PHP handler

    private function handler(?BotDetector $bots = null): DbHandler
    {
        $recorder = $this->createStub(ErrorRecorder::class);
        $recorder->method('record')->willReturnCallback(function (ErrorPayload $p): ?int {
            $this->payloads[] = $p;
            return 1;
        });
        $ip = $this->createStub(IpAnonymizer::class);
        $ip->method('process')->willReturnArgument(0);
        $guard = $this->createStub(DeploymentGuard::class);
        $guard->method('isCaptureSuspended')->willReturn(false);
        return new DbHandler($recorder, $this->scope(), $ip, $guard, new Fingerprinter(), \Monolog\Logger::NOTICE, true, $bots);
    }

    private function log(string $msg)
    {
        return $this->makeRecord('error', $msg);
    }

    public function testCliWithoutRequestDoesNotCrashAndSkipsBotFilter(): void
    {
        $bots = $this->createMock(BotDetector::class);
        $bots->expects($this->never())->method('isBot');
        $this->handler($bots)->handle($this->log('cli boom'));
        $this->assertCount(1, $this->payloads);
        $this->assertArrayNotHasKey('is_bot', $this->payloads[0]->context);
    }

    public function testWebBotRequestIsDroppedWhenFilterOn(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';
        $this->handler()->handle($this->log('web boom'));
        $this->assertSame([], $this->payloads);
    }

    public function testWebBotRequestIsFlaggedWhenFilterOff(): void
    {
        $this->overrides['panth_errormonitor/filters/ignore_bots'] = '0';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = 'Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm#07)';
        $this->handler()->handle($this->log('web boom'));
        $this->assertCount(1, $this->payloads);
        $this->assertTrue($this->payloads[0]->context['is_bot'] ?? false);
        $json = json_encode($this->payloads[0]->context, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('"is_bot":true', $json, 'digest LIKE %"is_bot":true% must match');
    }

    public function testHumanWebRequestIsNotFlagged(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
        $this->handler()->handle($this->log('web boom'));
        $this->assertCount(1, $this->payloads);
        $this->assertArrayNotHasKey('is_bot', $this->payloads[0]->context);
    }

    // ------------------------------------------------------------------ digest

    private function digest(): DigestBuilder
    {
        $scope = $this->scope();
        $tz = $this->createStub(TimezoneInterface::class);
        $tz->method('getConfigTimezone')->willReturn('UTC');
        return new DigestBuilder(
            $this->createStub(ErrorGroupResource::class),
            $this->createStub(ErrorEventResource::class),
            $this->createStub(Config::class),
            $scope,
            new BotDetector($scope),
            new NoiseFilter($scope),
            new TimeFormatter($tz),
            new PathMapper()
        );
    }

    private function grp(int $id, string $msg): array
    {
        return ['group_id' => $id, 'status' => 0, 'severity' => 'error', 'source' => 'js', 'error_type' => 'TypeError',
            'message' => $msg, 'file' => 'https://shop.test/app.js', 'line' => 1, 'occurrence_count' => 3,
            'first_seen_at' => '2026-10-28 10:00:00', 'last_seen_at' => '2026-10-28 11:00:00'];
    }

    public function testDigestBotShareAndBotOnlyExclusion(): void
    {
        $counts = [['group_id' => 1, 'cnt' => 3, 'last_at' => ''], ['group_id' => 2, 'cnt' => 4, 'last_at' => '']];
        $uas = [
            // group 1: all bots (one via stored flag with a human-looking UA, one via UA re-check)
            ['group_id' => 1, 'user_agent' => 'Mozilla/5.0 Chrome/130', 'flagged' => 1, 'cnt' => 1],
            ['group_id' => 1, 'user_agent' => 'Mozilla/5.0 (compatible; bingbot/2.0)', 'flagged' => 0, 'cnt' => 2],
            // group 2: 1 of 4 bots
            ['group_id' => 2, 'user_agent' => 'DotBot/1.2', 'flagged' => 0, 'cnt' => 1],
            ['group_id' => 2, 'user_agent' => 'Mozilla/5.0 Chrome/130', 'flagged' => 0, 'cnt' => 3],
        ];
        $groups = [$this->grp(1, 'bot only'), $this->grp(2, 'mixed')];
        $d = $this->digest()->assemble($counts, $uas, [], $groups, ['exclude_bot_only' => true]);
        $this->assertSame([2], array_column($d['rows'], 'group_id'));
        $this->assertSame(25, $d['rows'][0]['bot_pct']);
        $this->assertSame(1, $d['hidden_bot']);

        $d = $this->digest()->assemble($counts, $uas, [], $groups, ['exclude_bot_only' => false]);
        $this->assertSame(100, array_column($d['rows'], 'bot_pct', 'group_id')[1]);
    }
}
