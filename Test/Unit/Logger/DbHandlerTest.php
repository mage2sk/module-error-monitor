<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Panth\ErrorMonitor\Logger\DbHandler;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use PHPUnit\Framework\TestCase;

class DbHandlerTest extends TestCase
{
    private array $server = [];

    private array $flags = [
        'panth_errormonitor/general/enabled' => true,
        'panth_errormonitor/php_capture/enabled' => true,
    ];

    private array $values = [];

    private bool $suspended = false;

    /** @var ErrorPayload[] */
    private array $payloads = [];

    private ?\Throwable $recorderException = null;

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

    private function handler(): DbHandler
    {
        $recorder = $this->createStub(ErrorRecorder::class);
        $recorder->method('record')->willReturnCallback(function (ErrorPayload $payload): ?int {
            if ($this->recorderException !== null) {
                throw $this->recorderException;
            }
            $this->payloads[] = $payload;
            return 1;
        });
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path) => (bool)($this->flags[$path] ?? false));
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->values[$path] ?? null);
        $ip = $this->createStub(IpAnonymizer::class);
        $ip->method('process')->willReturnCallback(static fn (?string $v) => $v === null ? null : 'anon:' . $v);
        $guard = $this->createStub(DeploymentGuard::class);
        $guard->method('isCaptureSuspended')->willReturnCallback(fn () => $this->suspended);

        return new DbHandler($recorder, $scope, $ip, $guard, new Fingerprinter());
    }

    private function record(string $message, Level $level = Level::Error, array $context = [], string $channel = 'main'): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), $channel, $level, $message, $context);
    }

    public function testMasterSwitchOffSkipsCapture(): void
    {
        $this->flags['panth_errormonitor/general/enabled'] = false;
        $this->handler()->handle($this->record('boom'));
        $this->assertSame([], $this->payloads);
    }

    public function testPhpCaptureOffSkipsCapture(): void
    {
        $this->flags['panth_errormonitor/php_capture/enabled'] = false;
        $this->handler()->handle($this->record('boom'));
        $this->assertSame([], $this->payloads);
    }

    public function testSuspendedCaptureSkips(): void
    {
        $this->suspended = true;
        $this->handler()->handle($this->record('boom'));
        $this->assertSame([], $this->payloads);
    }

    public function testSeverityBelowMinimumIsSkipped(): void
    {
        $handler = $this->handler();
        $handler->handle($this->record('just a warning', Level::Warning));
        $this->assertSame([], $this->payloads);

        $this->values['panth_errormonitor/php_capture/min_severity'] = 'warning';
        $handler->handle($this->record('just a warning', Level::Warning));
        $this->assertCount(1, $this->payloads);
        $this->assertSame('warning', $this->payloads[0]->severity);
    }

    public function testRecordsBelowHandlerLevelAreNotHandled(): void
    {
        $this->values['panth_errormonitor/php_capture/min_severity'] = 'debug';
        $this->handler()->handle($this->record('debug noise', Level::Debug));
        $this->assertSame([], $this->payloads);
    }

    public function testInternalMarkerAndEmptyMessagesAreSkipped(): void
    {
        $handler = $this->handler();
        $handler->handle($this->record(ErrorRecorder::INTERNAL_MARKER . ' regroup failed'));
        $handler->handle($this->record(''));
        $this->assertSame([], $this->payloads);
    }

    public function testExceptionContextBuildsPayload(): void
    {
        $_SERVER['REQUEST_URI'] = '/checkout';
        $_SERVER['HTTP_REFERER'] = 'https://shop.test/cart';
        $_SERVER['HTTP_USER_AGENT'] = 'Agent/1.0';
        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $exception = new \DomainException('Real failure');
        $this->handler()->handle($this->record('wrapper', Level::Critical, [
            'exception' => $exception,
            'order' => 15,
            'flag' => true,
            'nested' => ['x' => 1],
            'obj' => new \stdClass(),
        ]));

        $this->assertCount(1, $this->payloads);
        $payload = $this->payloads[0];
        $this->assertSame('php', $payload->source);
        $this->assertSame('critical', $payload->severity);
        $this->assertSame(\DomainException::class, $payload->type);
        $this->assertSame('Real failure', $payload->message);
        $this->assertSame(__FILE__, $payload->file);
        $this->assertSame($exception->getLine(), $payload->line);
        $this->assertStringNotContainsString('Object(', (string)$payload->stackTrace);
        $this->assertSame(['channel' => 'main', 'order' => 15, 'flag' => true], $payload->context);
        $this->assertSame('/checkout', $payload->url);
        $this->assertSame('https://shop.test/cart', $payload->referer);
        $this->assertSame('Agent/1.0', $payload->userAgent);
        $this->assertSame('anon:10.1.2.3', $payload->ip);
        $this->assertSame('POST', $payload->httpMethod);
        $this->assertSame(0, $payload->storeId);
    }

    public function testExceptionWithEmptyMessageKeepsLogMessage(): void
    {
        $this->handler()->handle($this->record('log text', Level::Error, ['exception' => new \LogicException('')]));
        $this->assertSame('log text', $this->payloads[0]->message);
        $this->assertSame(\LogicException::class, $this->payloads[0]->type);
    }

    public function testInlineStackTraceIsSplitOff(): void
    {
        $this->handler()->handle($this->record(
            "RuntimeException: it broke\nStack trace:\n#0 /app/A.php(10): Foo->bar('secret')\n#1 {main}"
        ));
        $payload = $this->payloads[0];
        $this->assertSame('RuntimeException: it broke', $payload->message);
        $this->assertSame('RuntimeException', $payload->type);
        $this->assertSame("#0 /app/A.php(10): Foo->bar()\n#1 {main}", $payload->stackTrace);
        $this->assertNull($payload->file);
        $this->assertNull($payload->url);
        $this->assertNull($payload->ip);
    }

    public function testBareTraceMessageIsSynthesised(): void
    {
        $this->handler()->handle($this->record(
            "#0 /var/www/html/vendor/acme/lib/src/Job.php(55): Acme\\Lib\\Job->run(Array)\n#1 {main}",
            Level::Error,
            [],
            'cron'
        ));
        $payload = $this->payloads[0];
        $this->assertSame('Acme\\Lib\\Job->run() at acme/lib/src/Job.php:55', $payload->message);
        $this->assertSame('/var/www/html/vendor/acme/lib/src/Job.php', $payload->file);
        $this->assertSame(55, $payload->line);
        $this->assertSame('cron', $payload->type);
        $this->assertStringStartsWith('#0 /var/www/html/vendor/acme/lib/src/Job.php(55): Acme\\Lib\\Job->run()', (string)$payload->stackTrace);
    }

    public function testSynthesisedMessageShortensAppCodeAndBarePaths(): void
    {
        $this->handler()->handle($this->record("#0 /srv/app/code/Acme/Mod/Model/X.php(9): Acme\\Mod\\Model\\X::go()"));
        $this->handler()->handle($this->record('#0 /tmp/Other.php: Acme\\Y->go()'));
        $this->assertSame('Acme\\Mod\\Model\\X::go() at Acme/Mod/Model/X.php:9', $this->payloads[0]->message);
        $this->assertSame('Acme\\Y->go() at Other.php', $this->payloads[1]->message);
        $this->assertNull($this->payloads[1]->line);
    }

    public function testTypeFallsBackToChannelOrGenericError(): void
    {
        $handler = $this->handler();
        $handler->handle($this->record('plain failure text', Level::Error, [], 'payment'));
        $handler->handle($this->record('plain failure text', Level::Error, [], 'report'));
        $this->assertSame('payment', $this->payloads[0]->type);
        $this->assertSame('error', $this->payloads[1]->type);
    }

    public function testRecorderFailureIsSwallowed(): void
    {
        $this->recorderException = new \RuntimeException('db gone');
        $this->handler()->handle($this->record('boom'));
        $this->assertSame([], $this->payloads);
    }

    public function testLegacyArrayRecordIsNormalised(): void
    {
        $handler = $this->handler();
        $write = new \ReflectionMethod(DbHandler::class, 'write');
        $write->invoke($handler, [
            'level_name' => 'ALERT',
            'message' => 'array based',
            'context' => ['k' => 'v'],
            'channel' => 'legacy',
        ]);
        $write->invoke($handler, ['message' => 'defaults', 'context' => 'not-array']);

        $this->assertSame('alert', $this->payloads[0]->severity);
        $this->assertSame('legacy', $this->payloads[0]->type);
        $this->assertSame(['channel' => 'legacy', 'k' => 'v'], $this->payloads[0]->context);
        $this->assertSame('error', $this->payloads[1]->severity);
        $this->assertSame(['channel' => ''], $this->payloads[1]->context);
    }
}
