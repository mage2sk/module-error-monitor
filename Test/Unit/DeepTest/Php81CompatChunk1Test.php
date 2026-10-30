<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\DeepTest;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Logger\Monolog as MagentoMonolog;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Panth\ErrorMonitor\Logger\DbHandler;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use PHPUnit\Framework\TestCase;

/**
 * Deep test chunk 1: PHP 8.1 + Magento 2.4.7 (Monolog 2.x) compatibility.
 *
 * Every test here is written to run unchanged on Monolog 2 (Magento 2.4.7, array records)
 * and Monolog 3 (Magento 2.4.8+, LogRecord objects), and on PHPUnit 9 and 10+
 * (docblock data providers only, no attributes). Records are pushed through the real
 * Monolog Logger pipeline so the handler sees exactly the record shape the installed
 * Monolog version produces.
 */
class Php81CompatChunk1Test extends TestCase
{
    private array $server = [];

    /** @var ErrorPayload[] */
    private array $payloads = [];

    private array $values = ['panth_errormonitor/php_capture/min_severity' => 'notice'];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        foreach (['REQUEST_URI', 'HTTP_REFERER', 'HTTP_USER_AGENT', 'REMOTE_ADDR', 'REQUEST_METHOD'] as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/checkout/';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function handler(): DbHandler
    {
        $recorder = $this->createStub(ErrorRecorder::class);
        $recorder->method('record')->willReturnCallback(function (ErrorPayload $payload): ?int {
            $this->payloads[] = $payload;
            return 1;
        });
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(static fn ($path) => in_array($path, [
            'panth_errormonitor/general/enabled',
            'panth_errormonitor/php_capture/enabled',
        ], true));
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->values[$path] ?? null);
        $ip = $this->createStub(IpAnonymizer::class);
        $ip->method('process')->willReturnArgument(0);
        $guard = $this->createStub(DeploymentGuard::class);
        $guard->method('isCaptureSuspended')->willReturn(false);

        return new DbHandler($recorder, $scope, $ip, $guard, new Fingerprinter());
    }

    private function monologMajor(): int
    {
        return class_exists(\Monolog\LogRecord::class) ? 3 : 2;
    }

    public function testWriteSignatureIsCompatibleWithMonolog2And3(): void
    {
        $param = (new \ReflectionMethod(DbHandler::class, 'write'))->getParameters()[0];
        $this->assertNull(
            $param->getType(),
            'DbHandler::write() must keep an untyped parameter: Monolog 2 declares write(array), Monolog 3 write(LogRecord)'
        );
        $this->assertTrue(is_subclass_of(DbHandler::class, AbstractProcessingHandler::class));
    }

    public function testExceptionThroughMonologPipelineIsRecorded(): void
    {
        $logger = new Logger('main', [$this->handler()]);
        $logger->error('wrapper text', ['exception' => new \RuntimeException('Payment gateway down')]);

        $this->assertCount(1, $this->payloads, 'Monolog ' . $this->monologMajor() . ' record was not captured');
        $p = $this->payloads[0];
        $this->assertSame('error', $p->severity);
        $this->assertSame(\RuntimeException::class, $p->type);
        $this->assertSame('Payment gateway down', $p->message);
        $this->assertSame(__FILE__, $p->file);
        $this->assertSame('main', $p->context['channel']);
        $this->assertSame('GET', $p->httpMethod);
        $this->assertSame('/checkout/', $p->url);
    }

    /**
     * @dataProvider levelProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('levelProvider')]
    public function testLevelNameIsNormalisedOnBothMonologVersions(string $method, string $expected): void
    {
        $logger = new Logger('custom_channel', [$this->handler()]);
        $logger->{$method}('Something broke in module X');

        $this->assertCount(1, $this->payloads);
        $this->assertSame($expected, $this->payloads[0]->severity);
        $this->assertSame('custom_channel', $this->payloads[0]->type);
    }

    public static function levelProvider(): array
    {
        return [
            ['notice', 'notice'],
            ['warning', 'warning'],
            ['error', 'error'],
            ['critical', 'critical'],
            ['alert', 'alert'],
            ['emergency', 'emergency'],
        ];
    }

    public function testRecordsBelowHandlerLevelAreNotWritten(): void
    {
        $logger = new Logger('main', [$this->handler()]);
        $logger->info('just info');
        $logger->debug('just debug');
        $this->assertSame([], $this->payloads);
    }

    public function testMinimumSeverityFilterWorks(): void
    {
        $this->values['panth_errormonitor/php_capture/min_severity'] = 'error';
        $logger = new Logger('main', [$this->handler()]);
        $logger->warning('ignored warning');
        $logger->critical('kept critical');
        $this->assertCount(1, $this->payloads);
        $this->assertSame('critical', $this->payloads[0]->severity);
    }

    public function testProcessorExtraAndContextAreKept(): void
    {
        $logger = new Logger('main', [$this->handler()]);
        $logger->pushProcessor(static function ($record) {
            if (is_array($record)) {
                $record['extra']['request_id'] = 'abc-1';
                return $record;
            }
            return $record->with(extra: $record->extra + ['request_id' => 'abc-1']);
        });
        $logger->error('Order export failed', ['order_id' => 42, 'skus' => ['A', 'B']]);

        $this->assertCount(1, $this->payloads);
        $ctx = $this->payloads[0]->context;
        $this->assertSame(42, $ctx['order_id']);
        $this->assertSame(['A', 'B'], $ctx['skus']);
        $this->assertSame('abc-1', $ctx['extra_request_id']);
    }

    public function testMagentoFrameworkLoggerWrapperDeliversThrowableMessages(): void
    {
        $logger = new MagentoMonolog('main', [$this->handler()]);
        $logger->critical(new \LogicException('Invalid state in quote'));

        $this->assertCount(1, $this->payloads);
        $this->assertSame('critical', $this->payloads[0]->severity);
        // Magento\Framework\Logger\Monolog has no Throwable handling on 2.4.7 or 2.4.9: the
        // exception is cast to string by Monolog, so the handler must split the inline trace.
        $this->assertStringContainsString('Invalid state in quote', $this->payloads[0]->message);
        $this->assertStringNotContainsString('Stack trace:', $this->payloads[0]->message);
        $this->assertNotNull($this->payloads[0]->stackTrace);
        $this->assertSame(\LogicException::class, $this->payloads[0]->type);
    }

    public function testHandlerFailureNeverBreaksTheLogger(): void
    {
        $recorder = $this->createStub(ErrorRecorder::class);
        $recorder->method('record')->willThrowException(new \RuntimeException('DB gone away'));
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(true);
        $scope->method('getValue')->willReturn('notice');
        $guard = $this->createStub(DeploymentGuard::class);
        $handler = new DbHandler(
            $recorder,
            $scope,
            $this->createStub(IpAnonymizer::class),
            $guard,
            new Fingerprinter()
        );
        $logger = new Logger('main', [$handler]);
        $logger->error('still fine');
        $this->assertTrue(true);
    }
}
