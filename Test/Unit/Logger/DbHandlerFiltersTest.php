<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Logger;

use GraphQL\Error\Error as GraphQlError;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\ErrorMonitor\Logger\DbHandler;
use Panth\ErrorMonitor\Service\CliContext;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\GraphQlClientErrorFilter;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use Panth\ErrorMonitor\Test\Unit\MonologRecordTrait;
use PHPUnit\Framework\TestCase;

class DbHandlerFiltersTest extends TestCase
{
    use MonologRecordTrait;

    private array $server = [];

    private array $flags = [
        'panth_errormonitor/general/enabled' => true,
        'panth_errormonitor/php_capture/enabled' => true,
        'panth_errormonitor/filters/ignore_bots' => true,
    ];

    private array $values = [];

    private array $payloads = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        foreach (['REQUEST_URI', 'HTTP_REFERER', 'HTTP_USER_AGENT', 'REMOTE_ADDR', 'REQUEST_METHOD'] as $key) {
            unset($_SERVER[$key]);
        }
        GraphQlClientErrorFilter::reset();
        CliContext::setCronJob(null);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        GraphQlClientErrorFilter::reset();
        CliContext::setCronJob(null);
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

    private function record(string $message, array $context = [], string $level = 'error')
    {
        return $this->makeRecord($level, $message, $context);
    }

    private function webRequest(string $agent): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/graphql';
        $_SERVER['HTTP_USER_AGENT'] = $agent;
    }

    public function testClientSafeGraphQlErrorIsSkippedByDefault(): void
    {
        $this->webRequest('Mozilla/5.0 Chrome/130');
        $error = new GraphQlError('Cannot query field "stock_status" on type "ProductInterface".');
        $this->handler()->handle($this->record('x', ['exception' => $error]));
        $this->assertSame([], $this->payloads);

        $this->handler()->handle($this->record((string)$error));
        $this->assertCount(1, $this->payloads);

        $this->payloads = [];
        (new GraphQlClientErrorFilter($this->createStub(ScopeConfigInterface::class)))->register([$error]);
        $this->handler()->handle($this->record((string)$error));
        $this->assertSame([], $this->payloads);
    }

    public function testClientSafeGraphQlErrorRecordedAsNotice(): void
    {
        $this->webRequest('Mozilla/5.0 Chrome/130');
        $this->values[GraphQlClientErrorFilter::XML_MODE] = 'notice';
        $this->handler()->handle($this->record('x', ['exception' => new GraphQlError('The region_id does not match')]));
        $this->assertCount(1, $this->payloads);
        $this->assertSame('notice', $this->payloads[0]->severity);
        $this->assertTrue($this->payloads[0]->context[GraphQlClientErrorFilter::CONTEXT_FLAG]);
    }

    public function testClientSafeGraphQlErrorRecordModeKeepsSeverity(): void
    {
        $this->webRequest('Mozilla/5.0 Chrome/130');
        $this->values[GraphQlClientErrorFilter::XML_MODE] = 'record';
        $this->handler()->handle($this->record('x', ['exception' => new GraphQlError('Field missing')]));
        $this->assertSame('error', $this->payloads[0]->severity);
        $this->assertArrayNotHasKey(GraphQlClientErrorFilter::CONTEXT_FLAG, $this->payloads[0]->context);
    }

    public function testServerGraphQlErrorIsAlwaysRecorded(): void
    {
        $this->webRequest('Mozilla/5.0 Chrome/130');
        $error = new GraphQlError('Internal', null, null, [], null, new \RuntimeException('db'));
        $this->handler()->handle($this->record('x', ['exception' => $error]));
        $this->assertCount(1, $this->payloads);
        $this->assertSame('error', $this->payloads[0]->severity);
    }

    public function testBotRequestIsDroppedOrFlagged(): void
    {
        $this->webRequest('Mozilla/5.0 (compatible; bingbot/2.0)');
        $this->handler()->handle($this->record('Template failed'));
        $this->assertSame([], $this->payloads);

        $this->flags['panth_errormonitor/filters/ignore_bots'] = false;
        $this->handler()->handle($this->record('Template failed'));
        $this->assertCount(1, $this->payloads);
        $this->assertTrue($this->payloads[0]->context['is_bot']);
    }

    public function testCliEventCarriesSapiArgvCronJobAndTrace(): void
    {
        $_SERVER['argv'] = ['bin/magento', 'cron:run', '--group=default', '--db-password=topsecret'];
        CliContext::setCronJob('indexer_reindex_all_invalid');
        $this->handler()->handle($this->record('Cron failed without exception'));

        $context = $this->payloads[0]->context;
        $this->assertSame(PHP_SAPI, $context['sapi']);
        $this->assertSame('bin/magento cron:run --group=default --db-password=***', $context['argv']);
        $this->assertSame('indexer_reindex_all_invalid', $context['cron_job']);
        $this->assertNotNull($this->payloads[0]->stackTrace);
        $this->assertStringNotContainsString('Monolog', (string)$this->payloads[0]->stackTrace);
        $this->assertNull($this->payloads[0]->url);
    }

    public function testExceptionDetailsAreKeptInContext(): void
    {
        $inner = new \InvalidArgumentException('inner');
        $outer = new \RuntimeException('outer', 0, $inner);
        $this->handler()->handle($this->record('x', ['exception' => $outer, 'meta' => ['job' => 'a']]));

        $context = $this->payloads[0]->context;
        $this->assertSame(\RuntimeException::class, $context['exception_class']);
        $this->assertSame(__FILE__, $context['exception_file']);
        $this->assertSame($outer->getLine(), $context['exception_line']);
        $this->assertStringStartsWith(\InvalidArgumentException::class . ': inner', $context['previous'][0]);
        $this->assertSame(['job' => 'a'], $context['meta']);
    }
}
