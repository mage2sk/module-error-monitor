<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Controller\Js;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ErrorMonitor\Block\Email\Summary;
use Panth\ErrorMonitor\Controller\Js\Collect;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use Panth\ErrorMonitor\Model\Summary\DigestHolder;
use Panth\ErrorMonitor\Service\BotDetector;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use Panth\ErrorMonitor\Service\ClientIpResolver;
use Panth\ErrorMonitor\Service\IpNetworkFilter;
use Panth\ErrorMonitor\Service\JsReasonSerializer;
use Panth\ErrorMonitor\Service\NoiseFilter;
use Panth\ErrorMonitor\Service\PathMapper;
use Panth\ErrorMonitor\Service\RateLimiter;
use Panth\ErrorMonitor\Service\TimeFormatter;
use PHPUnit\Framework\TestCase;

class CollectIpFilterTest extends TestCase
{
    private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko)'
        . ' Chrome/99.0.4844.51 Safari/537.36';

    private array $flags = [
        BotDetector::XML_IGNORE_BOTS => true,
        IpNetworkFilter::XML_ENABLED => true,
    ];

    private array $values = [IpNetworkFilter::XML_NETWORKS => "116.179.0.0/16\n220.181.0.0/16"];

    private string $remoteIp = '116.179.37.10';

    private array $server = [];

    /** @var ErrorPayload[] */
    private array $payloads = [];

    private array $rateIps = [];

    private array $counter = [];

    protected function tearDown(): void
    {
        (new DigestHolder())->clear();
    }

    private function scope(): ScopeConfigInterface
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path) => (bool)($this->flags[$path] ?? false));
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->values[$path] ?? null);
        return $scope;
    }

    private function counterResource(): ResourceConnection
    {
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('quoteIdentifier')->willReturnArgument(0);
        $conn->method('query')->willReturnCallback(function ($sql, $bind) {
            $key = $bind[0] . '|' . $bind[1];
            $this->counter[$key] = ($this->counter[$key] ?? 0) + 1;
            return null;
        });
        $conn->method('fetchOne')->willReturnCallback(function ($sql, $bind) {
            $sum = 0;
            foreach ($this->counter as $key => $count) {
                [$hour, $reason] = explode('|', $key);
                if ($reason === $bind[0] && $hour >= $bind[1]) {
                    $sum += $count;
                }
            }
            return (string)$sum;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function filter(): IpNetworkFilter
    {
        return new IpNetworkFilter($this->scope(), $this->counterResource());
    }

    private function controller(IpNetworkFilter $filter): Collect
    {
        $server = $this->server + [
            'HTTP_ORIGIN' => 'https://shop.test',
            'HTTP_USER_AGENT' => self::CHROME_UA,
        ];
        $request = $this->createStub(HttpRequest::class);
        $request->method('getContent')->willReturn((string)json_encode([
            'message' => 'modal-popup.html HTTP status: 503',
            'name' => 'Error',
            'source' => 'https://shop.test/static/frontend/x/en_US/Magento_Ui/js/modal/modal.js',
        ]));
        $request->method('getServer')->willReturnCallback(static fn ($key) => $server[$key] ?? null);

        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnSelf();
        $raw->method('setHeader')->willReturnSelf();
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $config = $this->createStub(Config::class);
        $config->method('isJsCaptureEnabled')->willReturn(true);
        $config->method('getJsMaxBodyBytes')->willReturn(4096);
        $config->method('getJsRateLimitPerMinute')->willReturn(30);

        $rateLimiter = $this->createStub(RateLimiter::class);
        $rateLimiter->method('allow')->willReturnCallback(function ($ip): bool {
            $this->rateIps[] = $ip;
            return true;
        });
        $recorder = $this->createStub(ErrorRecorder::class);
        $recorder->method('record')->willReturnCallback(function (ErrorPayload $payload): int {
            $this->payloads[] = $payload;
            return 1;
        });
        $anonymizer = $this->createStub(IpAnonymizer::class);
        $anonymizer->method('process')->willReturnArgument(0);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getStores')->willReturn([$store]);

        $guard = $this->createStub(DeploymentGuard::class);
        $guard->method('isCaptureSuspended')->willReturn(false);
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
            $remote,
            new BotDetector($this->scope()),
            new JsReasonSerializer(),
            $filter
        );
    }

    private function footer(IpNetworkFilter $filter): string
    {
        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturnCallback(function () {
            $conn = $this->createStub(AdapterInterface::class);
            $conn->method('fetchOne')->willReturn(null);
            $conn->method('fetchAll')->willReturn([]);
            $select = $this->createStub(Select::class);
            $select->method('from')->willReturnSelf();
            $select->method('where')->willReturnSelf();
            $select->method('group')->willReturnSelf();
            $conn->method('select')->willReturn($select);
            $conn->method('quoteIdentifier')->willReturnArgument(0);
            return $conn;
        });
        $groups->method('getHourlyTable')->willReturn('panth_error_group_hourly');
        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getConnection')->willReturnCallback(fn () => $groups->getConnection());
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('UTC');
        $config = $this->createStub(Config::class);
        $config->method('getEmailMinSeverity')->willReturn('error');
        $config->method('getEmailMaxPerRun')->willReturn(50);
        $scope = $this->scope();
        $builder = new DigestBuilder(
            $groups,
            $events,
            $config,
            $scope,
            new BotDetector($scope),
            new NoiseFilter($scope),
            new TimeFormatter($timezone),
            new PathMapper(),
            $filter
        );
        (new DigestHolder())->set($builder->build());
        $block = new Summary(
            $this->createStub(Context::class),
            $this->createStub(CollectionFactory::class),
            $this->createStub(BackendUrl::class),
            [],
            new DigestHolder()
        );
        return implode("\n", $block->getFooterLines());
    }

    public function testJsErrorFromListedNetworkWithChromeUaIsNotStoredAndFooterCountsIt(): void
    {
        $filter = $this->filter();
        $this->controller($filter)->execute();
        $this->controller($filter)->execute();

        $this->assertSame([], $this->payloads);
        $this->assertSame(2, array_sum($this->counter));
        $this->assertStringContainsString('2 hidden by IP/network filter', $this->footer($filter));
    }

    public function testListedNetworkBehindCloudflareIsResolvedFromTheHeader(): void
    {
        $this->values[ClientIpResolver::XML_TRUSTED_PROXIES] = "# Cloudflare\n162.158.0.0/15";
        $this->remoteIp = '162.158.10.20';
        $this->server = ['HTTP_CF_CONNECTING_IP' => '116.179.37.10'];
        $this->controller($this->filter())->execute();

        $this->assertSame([], $this->payloads);
        $this->assertSame(1, array_sum($this->counter));
    }

    public function testSpoofedCloudflareHeaderFromUntrustedClientIsIgnored(): void
    {
        $this->remoteIp = '203.0.113.9';
        $this->server = ['HTTP_CF_CONNECTING_IP' => '116.179.37.10'];
        $this->controller($this->filter())->execute();

        $this->assertCount(1, $this->payloads);
        $this->assertSame('203.0.113.9', $this->payloads[0]->ip);
        $this->assertSame(['203.0.113.9'], $this->rateIps);
        $this->assertSame([], $this->counter);
    }

    public function testDefaultConfigurationCannotBeSpoofedFromAPrivateAddress(): void
    {
        $this->values[IpNetworkFilter::XML_NETWORKS] = '198.51.100.0/24';
        foreach (['172.18.0.5', '10.1.2.3', '127.0.0.1', '162.158.10.20'] as $remote) {
            $this->payloads = [];
            $this->rateIps = [];
            $this->remoteIp = $remote;
            $this->server = ['HTTP_CF_CONNECTING_IP' => '198.51.100.77', 'HTTP_X_FORWARDED_FOR' => '198.51.100.78'];
            $this->controller($this->filter())->execute();
            $this->assertCount(1, $this->payloads, 'forged header must not hide the report from ' . $remote);
            $this->assertSame($remote, $this->payloads[0]->ip);
            $this->assertSame([$remote], $this->rateIps, 'forged header must not change the rate limit key');
        }
        $this->assertSame([], $this->counter);
    }

    public function testStoredIpAndRateLimitUseTheResolvedClientIp(): void
    {
        $this->values[ClientIpResolver::XML_TRUSTED_PROXIES] = '162.158.0.0/15';
        $this->remoteIp = '162.158.10.20';
        $this->server = ['HTTP_CF_CONNECTING_IP' => '198.51.100.7'];
        $this->controller($this->filter())->execute();

        $this->assertCount(1, $this->payloads);
        $this->assertSame('198.51.100.7', $this->payloads[0]->ip);
        $this->assertSame('cf-connecting-ip', $this->payloads[0]->context['client_ip_source']);
        $this->assertSame(['198.51.100.7'], $this->rateIps);
    }

    public function testFilterOffStoresTheErrorAndFooterSaysNothingAboutIt(): void
    {
        $this->flags[IpNetworkFilter::XML_ENABLED] = false;
        $filter = $this->filter();
        $this->controller($filter)->execute();

        $this->assertCount(1, $this->payloads);
        $this->assertStringNotContainsString('IP/network filter', $this->footer($filter));
    }

    public function testTreatAsBotsStoresTheErrorAsBotWhenBotsAreKept(): void
    {
        $this->flags[IpNetworkFilter::XML_AS_BOTS] = true;
        $this->flags[BotDetector::XML_IGNORE_BOTS] = false;
        $this->controller($this->filter())->execute();

        $this->assertCount(1, $this->payloads);
        $this->assertTrue($this->payloads[0]->context['is_bot']);
        $this->assertTrue($this->payloads[0]->context[IpNetworkFilter::CONTEXT_FLAG]);
        $this->assertSame([], $this->counter);
    }

    public function testTreatAsBotsDropsAndCountsWhenBotsAreIgnored(): void
    {
        $this->flags[IpNetworkFilter::XML_AS_BOTS] = true;
        $this->controller($this->filter())->execute();

        $this->assertSame([], $this->payloads);
        $this->assertSame(1, array_sum($this->counter));
    }

    public function testDefaultNetworkListIsUsedWhenNotConfigured(): void
    {
        unset($this->values[IpNetworkFilter::XML_NETWORKS]);
        $filter = $this->filter();
        $this->assertSame(IpNetworkFilter::DROP, $filter->decide('220.181.1.1'));
        $this->assertSame(IpNetworkFilter::PASS, $filter->decide('8.8.8.8'));
        $this->assertSame(IpNetworkFilter::PASS, $filter->decide(null));
    }
}
