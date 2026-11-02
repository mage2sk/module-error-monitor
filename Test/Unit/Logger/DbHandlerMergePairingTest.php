<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Panth\ErrorMonitor\Logger\DbHandler;
use Panth\ErrorMonitor\Service\CliContext;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\GraphQlClientErrorFilter;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use Panth\ErrorMonitor\Service\IpNetworkFilter;
use Panth\ErrorMonitor\Test\Unit\MonologRecordTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DbHandlerMergePairingTest extends TestCase
{
    use MonologRecordTrait;

    private const MESSAGE = 'Redis session exceeded concurrent connections';

    private array $server = [];

    /** @var ErrorPayload[] */
    private array $payloads = [];

    private int $hidden = 0;

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
        $_SERVER['REQUEST_URI'] = '/customer/section/load/';
        $_SERVER['REQUEST_TIME_FLOAT'] = 1730000000.5;
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

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('quoteIdentifier')->willReturnArgument(0);
        $conn->method('query')->willReturnCallback(function () {
            $this->hidden++;
            return null;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);

        return new DbHandler(
            $recorder,
            $scope,
            $ip,
            $guard,
            new Fingerprinter(),
            \Monolog\Logger::NOTICE,
            true,
            null,
            null,
            null,
            new IpNetworkFilter($scope, $resource)
        );
    }

    private function exception(string $message = self::MESSAGE): array
    {
        return ['exception' => new LocalizedException(new Phrase($message))];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function plainLevels(): array
    {
        return [
            'error plain, critical exception' => ['error', 'critical'],
            'critical plain, critical exception' => ['critical', 'critical'],
            'alert plain, error exception' => ['alert', 'error'],
            'warning plain, critical exception' => ['warning', 'critical'],
        ];
    }

    /**
     * @dataProvider plainLevels
     */
    #[DataProvider('plainLevels')]
    public function testPlainLineIsMergedWhateverItsLevel(string $plainLevel, string $exceptionLevel): void
    {
        $this->values['panth_errormonitor/php_capture/min_severity'] = 'warning';
        $handler = $this->handler();
        $handler->handle($this->makeRecord($plainLevel, self::MESSAGE, ['report_id' => 'abc']));
        $handler->handle($this->makeRecord($exceptionLevel, self::MESSAGE, $this->exception()));
        $handler->close();

        $this->assertCount(1, $this->payloads);
        $this->assertSame(LocalizedException::class, $this->payloads[0]->type);
        $this->assertNotNull($this->payloads[0]->file);
        $rank = ['warning' => 5, 'error' => 6, 'critical' => 7, 'alert' => 8];
        $expected = $rank[$plainLevel] > $rank[$exceptionLevel] ? $plainLevel : $exceptionLevel;
        $this->assertSame($expected, $this->payloads[0]->severity);
        $this->assertTrue($this->payloads[0]->context['merged_log_line'] ?? false);
    }

    public function testSeveralHeldLinesArePairedWithTheirOwnException(): void
    {
        $handler = $this->handler();
        $handler->handle($this->makeRecord('error', 'Cart total mismatch'));
        $handler->handle($this->makeRecord('error', self::MESSAGE));
        $handler->handle($this->makeRecord('error', 'Unrelated plain line'));
        $handler->handle($this->makeRecord('critical', self::MESSAGE, $this->exception()));
        $handler->handle($this->makeRecord('critical', 'Cart total mismatch', $this->exception('Cart total mismatch')));
        $handler->close();

        $located = array_values(array_filter($this->payloads, static fn ($p) => $p->file !== null));
        $plain = array_values(array_filter($this->payloads, static fn ($p) => $p->file === null));
        $this->assertCount(2, $located);
        $this->assertSame(['Unrelated plain line'], array_map(static fn ($p) => $p->message, $plain));
    }

    public function testExceptionFirstAbsorbsExactlyOnePlainLine(): void
    {
        $handler = $this->handler();
        $handler->handle($this->makeRecord('critical', self::MESSAGE, $this->exception()));
        $handler->handle($this->makeRecord('error', self::MESSAGE));
        $handler->handle($this->makeRecord('error', self::MESSAGE));
        $handler->close();

        $this->assertCount(2, $this->payloads);
        $this->assertNotNull($this->payloads[0]->file);
        $this->assertNull($this->payloads[1]->file, 'The second plain line has no partner and is recorded.');
    }

    public function testNormalisedMessagesArePaired(): void
    {
        $handler = $this->handler();
        $handler->handle($this->makeRecord('error', '  redis SESSION exceeded   concurrent connections /customer/section/load/'));
        $handler->handle($this->makeRecord('critical', self::MESSAGE, $this->exception()));
        $handler->close();
        $this->assertCount(1, $this->payloads);
    }

    public function testDistinctMessagesAreNeverMerged(): void
    {
        $handler = $this->handler();
        $handler->handle($this->makeRecord('error', 'Order 1001 not found'));
        $handler->handle($this->makeRecord('critical', 'Order 1002 not found', $this->exception('Order 1002 not found')));
        $handler->close();
        $this->assertCount(2, $this->payloads);
    }

    public function testHeldLinesAreCapped(): void
    {
        $handler = $this->handler();
        for ($i = 0; $i < 55; $i++) {
            $handler->handle($this->makeRecord('error', 'Plain line ' . $i));
        }
        $this->assertCount(5, $this->payloads, 'Beyond 50 held lines the rest is recorded at once.');
        $handler->close();
        $this->assertCount(55, $this->payloads);
    }

    public function testDroppedPairIsCountedOnceInTheFooter(): void
    {
        $this->flags[IpNetworkFilter::XML_ENABLED] = true;
        $this->values[IpNetworkFilter::XML_NETWORKS] = '116.179.0.0/16';
        $_SERVER['REMOTE_ADDR'] = '116.179.37.10';
        $handler = $this->handler();
        $handler->handle($this->makeRecord('error', self::MESSAGE . ' /customer/section/load/'));
        $handler->handle($this->makeRecord('critical', self::MESSAGE, $this->exception()));
        $handler->handle($this->makeRecord('error', 'Another error'));
        $handler->handle($this->makeRecord('critical', self::MESSAGE, $this->exception()));
        $handler->close();

        $this->assertSame([], $this->payloads);
        $this->assertSame(3, $this->hidden, 'pair = 1, unrelated line = 1, unpaired second exception = 1');
    }

    public function testCliRecordsAreWrittenAtOnce(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        $handler = $this->handler();
        $handler->handle($this->makeRecord('critical', self::MESSAGE));
        $handler->handle($this->makeRecord('critical', self::MESSAGE, $this->exception()));
        $this->assertCount(2, $this->payloads);
    }

    public function testNormalizeMessage(): void
    {
        $this->assertSame(
            'redis session exceeded concurrent connections',
            DbHandler::normalizeMessage(
                'Magento\Framework\Exception\LocalizedException: ' . self::MESSAGE
                . " in /var/www/html/vendor/magento/framework/Session/SaveHandler/Redis.php:86\nStack trace:\n#0 x"
            )
        );
        $this->assertSame(
            'redis session exceeded concurrent connections',
            DbHandler::normalizeMessage(self::MESSAGE . ' /checkout/', '/checkout/')
        );
        $this->assertSame('order 1001 not found', DbHandler::normalizeMessage('Order 1001 not found'));
    }
}
