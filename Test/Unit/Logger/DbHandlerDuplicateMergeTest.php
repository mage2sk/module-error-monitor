<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Panth\ErrorMonitor\Logger\DbHandler;
use Panth\ErrorMonitor\Service\CliContext;
use Panth\ErrorMonitor\Service\ClientIpResolver;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\GraphQlClientErrorFilter;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use Panth\ErrorMonitor\Service\IpNetworkFilter;
use Panth\ErrorMonitor\Test\Unit\MonologRecordTrait;
use PHPUnit\Framework\TestCase;

class DbHandlerDuplicateMergeTest extends TestCase
{
    use MonologRecordTrait;

    private const MESSAGE = 'Redis session exceeded concurrent connections';

    private array $server = [];

    /** @var ErrorPayload[] */
    private array $payloads = [];

    private array $flags = [
        'panth_errormonitor/general/enabled' => true,
        'panth_errormonitor/php_capture/enabled' => true,
        'panth_errormonitor/filters/ignore_bots' => true,
    ];

    private array $values = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/checkout/cart/';
        $_SERVER['REQUEST_TIME_FLOAT'] = 1730000000.25;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/99.0.4844.51 Safari/537.36';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        GraphQlClientErrorFilter::reset();
        CliContext::setCronJob(null);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        GraphQlClientErrorFilter::reset();
    }

    private function handler(): DbHandler
    {
        $recorder = $this->createStub(ErrorRecorder::class);
        $recorder->method('record')->willReturnCallback(function (ErrorPayload $payload): int {
            $this->payloads[] = $payload;
            return 1;
        });
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path) => (bool)($this->flags[$path] ?? false));
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->values[$path] ?? null);
        $ip = $this->createStub(IpAnonymizer::class);
        $ip->method('process')->willReturnArgument(0);
        $guard = $this->createStub(DeploymentGuard::class);
        $guard->method('isCaptureSuspended')->willReturn(false);
        return new DbHandler($recorder, $scope, $ip, $guard, new Fingerprinter());
    }

    private function exception(): LocalizedException
    {
        return new LocalizedException(new Phrase(self::MESSAGE));
    }

    public function testPlainLogLineBeforeTheExceptionIsMergedIntoOneLocatedRecord(): void
    {
        $handler = $this->handler();
        $handler->handle($this->makeRecord('error', self::MESSAGE));
        $this->assertSame([], $this->payloads, 'plain line waits for a possible exception record');

        $handler->handle($this->makeRecord('critical', self::MESSAGE, ['exception' => $this->exception()]));
        $handler->close();

        $this->assertCount(1, $this->payloads);
        $this->assertSame(LocalizedException::class, $this->payloads[0]->type);
        $this->assertNotNull($this->payloads[0]->file);
        $this->assertNotNull($this->payloads[0]->line);
        $this->assertNotNull($this->payloads[0]->stackTrace);
    }

    public function testPlainLogLineAfterTheExceptionIsDropped(): void
    {
        $handler = $this->handler();
        $handler->handle($this->makeRecord('critical', self::MESSAGE, ['exception' => $this->exception()]));
        $handler->handle($this->makeRecord('error', self::MESSAGE));
        $handler->close();

        $this->assertCount(1, $this->payloads);
        $this->assertSame(LocalizedException::class, $this->payloads[0]->type);
    }

    public function testPlainLineWithoutMatchingExceptionIsStillRecorded(): void
    {
        $handler = $this->handler();
        $handler->handle($this->makeRecord('error', 'Something else failed'));
        $handler->handle($this->makeRecord('critical', self::MESSAGE, ['exception' => $this->exception()]));
        $handler->handle($this->makeRecord('error', 'Last plain line'));
        $this->assertCount(1, $this->payloads, 'plain lines are held until the end of the request');
        $handler->close();

        $this->assertSame(
            [self::MESSAGE, 'Something else failed', 'Last plain line'],
            array_map(static fn (ErrorPayload $p): string => $p->message, $this->payloads)
        );
    }

    public function testPendingLineIsFlushedWhenTheHandlerIsDestroyed(): void
    {
        $handler = $this->handler();
        $handler->handle($this->makeRecord('error', self::MESSAGE));
        unset($handler);
        $this->assertCount(1, $this->payloads);
        $this->assertSame(self::MESSAGE, $this->payloads[0]->message);
    }

    public function testANewRequestDoesNotReuseTheLocatedMessagesOfThePreviousOne(): void
    {
        $handler = $this->handler();
        $handler->handle($this->makeRecord('critical', self::MESSAGE, ['exception' => $this->exception()]));
        $_SERVER['REQUEST_TIME_FLOAT'] = 1730000099.5;
        $handler->handle($this->makeRecord('error', self::MESSAGE));
        $handler->close();
        $this->assertCount(2, $this->payloads);
    }

    public function testCliRecordsAreNotDeferred(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        $handler = $this->handler();
        $handler->handle($this->makeRecord('error', self::MESSAGE));
        $this->assertCount(1, $this->payloads);
    }

    public function testIpFilterDropsPhpErrorsFromListedNetworkBehindCloudflare(): void
    {
        $this->flags[IpNetworkFilter::XML_ENABLED] = true;
        $this->values[IpNetworkFilter::XML_NETWORKS] = "116.179.0.0/16";
        $this->values[ClientIpResolver::XML_TRUSTED_PROXIES] = "# Cloudflare\n162.158.0.0/15";
        $_SERVER['REMOTE_ADDR'] = '162.158.10.20';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '116.179.37.10';
        $handler = $this->handler();
        $handler->handle($this->makeRecord('critical', self::MESSAGE, ['exception' => $this->exception()]));
        $handler->close();
        $this->assertSame([], $this->payloads);
    }

    public function testDefaultConfigurationCannotBeSpoofedFromAPrivateAddress(): void
    {
        $this->flags[IpNetworkFilter::XML_ENABLED] = true;
        $this->values[IpNetworkFilter::XML_NETWORKS] = '198.51.100.0/24';
        $_SERVER['REMOTE_ADDR'] = '172.18.0.5';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.78';
        $handler = $this->handler();
        $handler->handle($this->makeRecord('critical', self::MESSAGE, ['exception' => $this->exception()]));
        $this->assertCount(1, $this->payloads, 'the forged header must neither hide nor relabel the error');
        $this->assertSame('172.18.0.5', $this->payloads[0]->ip);
        $this->assertArrayNotHasKey('client_ip_source', $this->payloads[0]->context);
    }

    public function testStoredIpIsTheResolvedClientIp(): void
    {
        $this->values[ClientIpResolver::XML_TRUSTED_PROXIES] = '162.158.0.0/15';
        $_SERVER['REMOTE_ADDR'] = '162.158.10.20';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
        $handler = $this->handler();
        $handler->handle($this->makeRecord('critical', self::MESSAGE, ['exception' => $this->exception()]));
        $this->assertSame('198.51.100.7', $this->payloads[0]->ip);
        $this->assertSame('cf-connecting-ip', $this->payloads[0]->context['client_ip_source'] ?? null);
    }

    public function testNetworksTreatedAsBotsAreStoredWithTheBotFlag(): void
    {
        $this->flags[IpNetworkFilter::XML_ENABLED] = true;
        $this->flags[IpNetworkFilter::XML_AS_BOTS] = true;
        $this->flags['panth_errormonitor/filters/ignore_bots'] = false;
        $this->values[IpNetworkFilter::XML_NETWORKS] = "116.179.0.0/16";
        $_SERVER['REMOTE_ADDR'] = '116.179.37.10';
        $handler = $this->handler();
        $handler->handle($this->makeRecord('critical', self::MESSAGE, ['exception' => $this->exception()]));
        $this->assertCount(1, $this->payloads);
        $this->assertTrue($this->payloads[0]->context['is_bot'] ?? false);
        $this->assertTrue($this->payloads[0]->context[IpNetworkFilter::CONTEXT_FLAG] ?? false);
    }
}
