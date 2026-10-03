<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Service\CaptureThrottle;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\Fingerprinter;
use PHPUnit\Framework\TestCase;

class ErrorRecorderTest extends TestCase
{
    private array $queries = [];

    private array $inserts = [];

    private int $lastInsertId = 42;

    private ?\Throwable $queryException = null;

    private array $configValues = [];

    private array $configFlags = [];

    private int $throttleResult = 1;

    private array $throttleCalls = [];

    private ?\Closure $throttleHook = null;

    private function recorder(): ErrorRecorder
    {
        $conn = $this->createStub(Mysql::class);
        $conn->method('quoteIdentifier')->willReturnCallback(static fn ($v) => '`' . $v . '`');
        $conn->method('query')->willReturnCallback(function ($sql, $bind = []) {
            if ($this->queryException !== null) {
                throw $this->queryException;
            }
            $this->queries[] = ['sql' => $sql, 'bind' => $bind];
            return null;
        });
        $conn->method('lastInsertId')->willReturnCallback(fn () => (string)$this->lastInsertId);
        $conn->method('insert')->willReturnCallback(function ($table, array $row): int {
            $this->inserts[] = ['table' => $table, 'row' => $row];
            return 1;
        });

        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($conn);
        $groups->method('getMainTable')->willReturn('panth_error_group');
        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getConnection')->willReturn($conn);
        $events->method('getMainTable')->willReturn('panth_error_event');

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->configValues[$path] ?? null);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path) => (bool)($this->configFlags[$path] ?? false));

        $throttle = $this->createStub(CaptureThrottle::class);
        $throttle->method('register')->willReturnCallback(function (string $fp, int $window): int {
            $this->throttleCalls[] = [$fp, $window];
            if ($this->throttleHook !== null) {
                ($this->throttleHook)();
            }
            return $this->throttleResult;
        });

        return new ErrorRecorder($groups, $events, new Fingerprinter(), $scope, $throttle);
    }

    private function payload(array $overrides = []): ErrorPayload
    {
        $args = $overrides + [
            'source' => 'php',
            'severity' => 'error',
            'type' => 'RuntimeException',
            'message' => 'Something failed',
        ];
        return new ErrorPayload(...$args);
    }

    public function testBlankMessageIsSkipped(): void
    {
        $this->assertNull($this->recorder()->record($this->payload(['message' => "  \n"])));
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->throttleCalls);
    }

    public function testHappyPathUpsertsGroupAndInsertsEvent(): void
    {
        $recorder = $this->recorder();
        $payload = $this->payload([
            'severity' => ' WARNING ',
            'file' => '/var/www/app/Foo.php',
            'line' => 12,
            'stackTrace' => '#0 trace',
            'url' => 'https://shop.test/p?token=abc&q=1&Email=a@b.c&flag#frag',
            'referer' => 'https://shop.test/ref',
            'userAgent' => 'UA',
            'ip' => '10.0.0.1',
            'httpMethod' => 'GET',
            'context' => ['channel' => 'main', 'path' => '/a/b'],
            'storeId' => 2,
        ]);

        $this->assertSame(42, $recorder->record($payload));

        $this->assertCount(1, $this->queries);
        $query = $this->queries[0];
        $this->assertStringStartsWith('INSERT INTO `panth_error_group`', $query['sql']);
        $this->assertStringContainsString('occurrence_count = occurrence_count + 1', $query['sql']);
        $expectedFp = (new Fingerprinter())->fingerprint('php', 'RuntimeException', 'Something failed', '/var/www/app/Foo.php', 12);
        $this->assertSame($expectedFp, $query['bind'][0]);
        $this->assertSame('php', $query['bind'][1]);
        $this->assertSame('warning', $query['bind'][2]);
        $this->assertSame('RuntimeException', $query['bind'][3]);
        $this->assertSame('Something failed', $query['bind'][4]);
        $this->assertSame('/var/www/app/Foo.php', $query['bind'][5]);
        $this->assertSame(12, $query['bind'][6]);
        $this->assertSame(2, $query['bind'][7]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $query['bind'][8]);

        $this->assertCount(1, $this->inserts);
        $insert = $this->inserts[0];
        $this->assertSame('panth_error_event', $insert['table']);
        $row = $insert['row'];
        $this->assertSame(42, $row['group_id']);
        $this->assertSame('https://shop.test/p?token=redacted&q=1&Email=redacted&flag', $row['url']);
        $this->assertSame('https://shop.test/ref', $row['referer']);
        $this->assertSame('UA', $row['user_agent']);
        $this->assertSame('10.0.0.1', $row['ip']);
        $this->assertSame('GET', $row['http_method']);
        $this->assertSame('#0 trace', $row['stack_trace']);
        $this->assertSame('{"channel":"main","path":"/a/b"}', $row['context']);
        $this->assertSame(2, $row['store_id']);
    }

    public function testOptionalFieldsAreStoredAsNull(): void
    {
        $this->recorder()->record($this->payload(['type' => '', 'severity' => 'bogus']));

        $bind = $this->queries[0]['bind'];
        $this->assertSame('error', $bind[2]);
        $this->assertNull($bind[3]);
        $this->assertNull($bind[5]);
        $this->assertNull($bind[6]);

        $row = $this->inserts[0]['row'];
        foreach (['url', 'referer', 'user_agent', 'ip', 'http_method', 'stack_trace', 'context'] as $field) {
            $this->assertNull($row[$field], $field);
        }
    }

    public function testValuesAreCappedAndControlCharactersStripped(): void
    {
        $this->recorder()->record($this->payload([
            'source' => 'javascript-long',
            'message' => "Bad\x00\x07 value " . str_repeat('x', 5000),
            'httpMethod' => 'VERYLONGMETHODNAME',
            'url' => 'https://shop.test/plain?',
        ]));

        $bind = $this->queries[0]['bind'];
        $this->assertSame('javascri', $bind[1]);
        $this->assertSame(4000, mb_strlen($bind[4]));
        $this->assertStringStartsWith('Bad value ', $bind[4]);
        $row = $this->inserts[0]['row'];
        $this->assertSame('VERYLONGME', $row['http_method']);
        $this->assertSame('https://shop.test/plain', $row['url']);
    }

    public function testThrottleIncrementIsAddedToCount(): void
    {
        $this->throttleResult = 5;
        $this->recorder()->record($this->payload());
        $this->assertStringContainsString('occurrence_count = occurrence_count + 5', $this->queries[0]['sql']);
        $this->assertStringContainsString(', 5, ?, ?, ?, ?, ?)', $this->queries[0]['sql']);
    }

    public function testCoalescedOccurrenceWritesNothing(): void
    {
        $this->throttleResult = 0;
        $this->assertNull($this->recorder()->record($this->payload()));
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->inserts);
    }

    public function testMissingGroupIdSkipsEventInsert(): void
    {
        $this->lastInsertId = 0;
        $this->assertNull($this->recorder()->record($this->payload()));
        $this->assertCount(1, $this->queries);
        $this->assertSame([], $this->inserts);
    }

    public function testDatabaseFailureIsSwallowed(): void
    {
        $this->queryException = new \RuntimeException('db down');
        $this->assertNull($this->recorder()->record($this->payload()));
    }

    public function testThrottleWindowFromConfig(): void
    {
        $cases = [[null, 60], ['', 60], ['0', 0], ['-5', 0], ['300', 300]];
        foreach ($cases as [$raw, $expected]) {
            $this->throttleCalls = [];
            $this->configValues = ['panth_errormonitor/php_capture/throttle_window_seconds' => $raw];
            $this->recorder()->record($this->payload());
            $this->assertSame($expected, $this->throttleCalls[0][1], var_export($raw, true));
        }
    }

    public function testIgnorePatternMatchesAnyFieldCaseInsensitively(): void
    {
        $this->configValues = ['panth_errormonitor/general/ignore_patterns' => "\n  Vendor/Noisy  \nother"];
        $recorder = $this->recorder();

        $this->assertNull($recorder->record($this->payload(['file' => '/app/vendor/noisy/A.php'])));
        $this->assertNull($recorder->record($this->payload(['stackTrace' => 'at OTHER place'])));
        $this->assertSame(42, $recorder->record($this->payload(['message' => 'unrelated'])));
        $this->assertCount(1, $this->queries);
    }

    public function testIgnorePatternFallsBackToLegacyPath(): void
    {
        $this->configValues = ['panth_errormonitor/js_capture/ignore_patterns' => 'runtimeexception'];
        $this->assertNull($this->recorder()->record($this->payload()));
        $this->assertSame([], $this->queries);
    }

    public function testEcosystemAlertsFilteredOnlyWhenFlagEnabled(): void
    {
        $message = '[PanthWaf] BLOCKED request from bot';

        $this->assertSame(42, $this->recorder()->record($this->payload(['message' => $message])));

        $this->queries = [];
        $this->configFlags = ['panth_errormonitor/general/filter_ecosystem_alerts' => true];
        $recorder = $this->recorder();
        $this->assertNull($recorder->record($this->payload(['message' => $message])));
        $this->assertSame(42, $recorder->record($this->payload(['message' => '[PanthWaf] ERROR real problem'])));
        $this->assertCount(1, $this->queries);
    }

    public function testReentrantCallsAreIgnored(): void
    {
        $recorder = $this->recorder();
        $inner = 'unset';
        $this->throttleHook = function () use ($recorder, &$inner): void {
            $this->throttleHook = null;
            $inner = $recorder->record($this->payload(['message' => 'nested']));
        };

        $this->assertSame(42, $recorder->record($this->payload()));
        $this->assertNull($inner);
        $this->assertCount(1, $this->queries);

        $this->assertSame(42, $recorder->record($this->payload()), 'Guard is released afterwards');
    }
}
