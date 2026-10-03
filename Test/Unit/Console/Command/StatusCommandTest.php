<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Console\Command;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\ErrorMonitor\Console\Command\StatusCommand;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class StatusCommandTest extends TestCase
{
    private array $status = [];

    private int $resets = 0;

    private bool $dbFails = false;

    private array $wheres = [];

    private array $flags = [];

    private array $values = [];

    protected function setUp(): void
    {
        $this->status = [
            'maintenance' => false,
            'paused_until' => null,
            'auto_pause_until' => null,
            'auto_pause_reason' => '',
            'watched_mtimes' => [],
            'last_seen_mtimes' => [],
            'window_minutes' => 5,
            'suspended' => false,
        ];
    }

    private function tester(): CommandTester
    {
        $guard = $this->createStub(DeploymentGuard::class);
        $guard->method('status')->willReturnCallback(fn () => $this->status);
        $guard->method('resetAutoDetect')->willReturnCallback(function (): void {
            $this->resets++;
        });

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path) => (bool)($this->flags[$path] ?? false));
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->values[$path] ?? null);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        $selectCount = 0;
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('quoteIdentifier')->willReturnCallback(static fn ($v) => '`' . $v . '`');
        $conn->method('fetchOne')->willReturnCallback(function ($sql) use (&$selectCount) {
            if (is_string($sql)) {
                return $sql === 'SELECT COUNT(*) FROM `pfx_panth_error_group`' ? '77' : '-1';
            }
            $selectCount++;
            return $selectCount === 1 ? '3' : '9';
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturnCallback(function () use ($conn) {
            if ($this->dbFails) {
                throw new \RuntimeException('Connection refused');
            }
            return $conn;
        });
        $resource->method('getTableName')->willReturnCallback(static fn ($name) => 'pfx_' . $name);

        $state = $this->createStub(State::class);
        $state->method('setAreaCode')->willThrowException(new \RuntimeException('already set'));

        $command = new StatusCommand($guard, $scope, $resource, $state);
        $this->assertSame('panth:errormonitor:status', $command->getName());
        return new CommandTester($command);
    }

    public function testActiveCaptureReport(): void
    {
        $this->flags = [
            'panth_errormonitor/general/enabled' => true,
            'panth_errormonitor/php_capture/enabled' => true,
            'panth_errormonitor/general/filter_ecosystem_alerts' => true,
        ];
        $this->values = [
            'panth_errormonitor/php_capture/min_severity' => 'warning',
            'panth_errormonitor/general/ignore_patterns' => "one\n\n  two  \r\nthree",
        ];
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $out = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/Master switch \(general\/enabled\)\s+yes/', $out);
        $this->assertMatchesRegularExpression('/JS capture \(js_capture\/enabled\)\s+no/', $out);
        $this->assertMatchesRegularExpression('/Min PHP severity to capture\s+warning/', $out);
        $this->assertMatchesRegularExpression('/Explicit pause flag \(CLI\)\s+no/', $out);
        $this->assertMatchesRegularExpression('/Auto-pause reason\s+\(n\/a\)/', $out);
        $this->assertStringContainsString('>>> CAPTURE IS ACTIVE <<<', $out);
        $this->assertStringContainsString('(no watched paths exist)', $out);
        $this->assertMatchesRegularExpression('/Ecosystem-alert filter on\s+yes/', $out);
        $this->assertMatchesRegularExpression('/Ignore-patterns lines\s+3/', $out);
        $this->assertMatchesRegularExpression('/Events recorded in last hour\s+3/', $out);
        $this->assertMatchesRegularExpression('/Events recorded in last 24h\s+9/', $out);
        $this->assertMatchesRegularExpression('/Total error groups in table\s+77/', $out);
        $this->assertStringNotContainsString('Tip:', $out);
        $this->assertSame(0, $this->resets);

        $this->assertSame('created_at >= ?', $this->wheres[0][0]);
        $this->assertEqualsWithDelta(time() - 3600, strtotime($this->wheres[0][1] . ' UTC'), 5);
        $this->assertEqualsWithDelta(time() - 86400, strtotime($this->wheres[1][1] . ' UTC'), 5);
    }

    public function testSuspendedReportWithMtimesAndReset(): void
    {
        $now = time();
        $long = '/very/long/path/' . str_repeat('segment/', 10) . 'deployed_version.txt';
        $this->status = [
            'maintenance' => true,
            'paused_until' => 1767225600,
            'auto_pause_until' => 1767229200,
            'auto_pause_reason' => 'deploy detected',
            'watched_mtimes' => [
                '/root/a' => $now - 30,
                '/root/b' => $now - 300,
                '/root/c' => $now - 7200,
                $long => $now - 3 * 86400,
            ],
            'last_seen_mtimes' => ['/root/a' => 1767225600, '/root/gone' => 1767225600],
            'window_minutes' => 10,
            'suspended' => true,
        ];
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--reset-auto-detect' => true]));
        $out = $tester->getDisplay();
        $this->assertSame(1, $this->resets);
        $this->assertStringContainsString('Auto-detect baseline + pause-until flags cleared.', $out);
        $this->assertMatchesRegularExpression('/Magento maintenance mode\s+yes/', $out);
        $this->assertStringContainsString('YES until 2026-01-01 00:00:00 UTC', $out);
        $this->assertStringContainsString('YES until 2026-01-01 01:00:00 UTC', $out);
        $this->assertMatchesRegularExpression('/Auto-pause window \(min\)\s+10/', $out);
        $this->assertMatchesRegularExpression('/Auto-pause reason\s+deploy detected/', $out);
        $this->assertStringContainsString('>>> CAPTURE IS SUSPENDED <<<', $out);
        $this->assertStringContainsString('30s ago', $out);
        $this->assertStringContainsString('5m ago', $out);
        $this->assertStringContainsString('2h ago', $out);
        $this->assertStringContainsString('3d ago', $out);
        $this->assertMatchesRegularExpression('/\/root\/gone\s+\(missing\)\s+2026-01-01 00:00:00\s+-/', $out);
        $this->assertMatchesRegularExpression('/\/root\/b\s+\S+ \S+\s+\(none\)/', $out);
        $this->assertStringContainsString('...', $out);
        $this->assertStringNotContainsString('/very/long/path/', $out);
        $this->assertStringContainsString('Tip: --reset-auto-detect', $out);
    }

    public function testDatabaseFailureIsReportedInline(): void
    {
        $this->dbFails = true;
        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertMatchesRegularExpression('/DB query failed\s+Connection refused/', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/Ignore-patterns lines\s+0/', $tester->getDisplay());
    }

    public function testShortenKeepsShortPathsAndTrimsLongOnes(): void
    {
        $command = new StatusCommand(
            $this->createStub(DeploymentGuard::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(ResourceConnection::class),
            $this->createStub(State::class)
        );
        $shorten = new \ReflectionMethod($command, 'shorten');
        $this->assertSame('/short', $shorten->invoke($command, '/short', 10));
        $this->assertSame('...89abcde', $shorten->invoke($command, '0123456789abcde', 10));
        $this->assertSame(10, mb_strlen($shorten->invoke($command, '0123456789abcde', 10)));
    }
}
