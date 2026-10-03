<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Cron;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\ErrorMonitor\Cron\Cleanup;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupEdgeCasesTest extends TestCase
{
    private array $calls = [];

    private array $warnings = [];

    private function cleanup(?\Throwable $failure = null): Cleanup
    {
        $config = $this->createStub(Config::class);
        $config->method('getEventRetentionDays')->willReturn(7);
        $config->method('getResolvedGroupRetentionDays')->willReturn(14);
        $config->method('getUnresolvedGroupRetentionDays')->willReturn(0);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willReturnCallback(function ($table, $where) use ($failure): int {
            $this->calls[] = [$table, $where];
            if ($failure !== null && count($this->calls) === 2) {
                throw $failure;
            }
            return count($this->calls) * 10;
        });

        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getConnection')->willReturn($connection);
        $events->method('getMainTable')->willReturn('panth_error_event');
        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($connection);
        $groups->method('getMainTable')->willReturn('panth_error_group');

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = $message;
        });

        return new Cleanup($config, $events, $groups, $logger);
    }

    public function testCutoffsAndTablesForEachRetentionRule(): void
    {
        $result = $this->cleanup()->run();

        $this->assertSame(['events' => 10, 'groups' => 20, 'unresolved_groups' => 0], $result);
        $this->assertSame('panth_error_event', $this->calls[0][0]);
        $eventCutoff = strtotime($this->calls[0][1]['created_at < ?'] . ' UTC');
        $this->assertEqualsWithDelta(time() - 7 * 86400, $eventCutoff, 5);

        $this->assertSame('panth_error_group', $this->calls[1][0]);
        $this->assertSame(ErrorGroup::STATUS_RESOLVED, $this->calls[1][1]['status = ?']);
        $groupCutoff = strtotime($this->calls[1][1]['last_seen_at < ?'] . ' UTC');
        $this->assertEqualsWithDelta(time() - 14 * 86400, $groupCutoff, 5);
    }

    public function testFailureIsLoggedAndPartialCountsReturned(): void
    {
        $result = $this->cleanup(new \RuntimeException('table locked'))->run();

        $this->assertSame(['events' => 10, 'groups' => 0, 'unresolved_groups' => 0], $result);
        $this->assertSame(['[PanthErrorMonitor] cleanup failed: table locked'], $this->warnings);
    }

    public function testExecuteRunsCleanup(): void
    {
        $this->cleanup()->execute();
        $this->assertCount(2, $this->calls);
        $this->assertSame([], $this->warnings);
    }
}
