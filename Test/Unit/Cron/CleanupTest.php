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

class CleanupTest extends TestCase
{
    public function testUnresolvedGroupsAreKeptWhenRetentionIsZero(): void
    {
        $calls = [];
        $result = $this->cleanup(0, $calls)->run();

        $this->assertCount(2, $calls);
        $this->assertSame(ErrorGroup::STATUS_RESOLVED, $calls[1]['status = ?']);
        $this->assertSame(0, $result['unresolved_groups']);
    }

    public function testOnlyNewGroupsArePrunedWhenRetentionIsSet(): void
    {
        $calls = [];
        $result = $this->cleanup(60, $calls)->run();

        $this->assertCount(3, $calls);
        $this->assertSame(ErrorGroup::STATUS_NEW, $calls[2]['status = ?']);
        $cutoff = strtotime($calls[2]['last_seen_at < ?'] . ' UTC');
        $this->assertEqualsWithDelta(time() - 60 * 86400, $cutoff, 5);
        $this->assertSame(4, $result['unresolved_groups']);
    }

    private function cleanup(int $unresolvedDays, array &$calls): Cleanup
    {
        $config = $this->createStub(Config::class);
        $config->method('getEventRetentionDays')->willReturn(30);
        $config->method('getResolvedGroupRetentionDays')->willReturn(90);
        $config->method('getUnresolvedGroupRetentionDays')->willReturn($unresolvedDays);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willReturnCallback(
            function (string $table, array $where) use (&$calls): int {
                $calls[] = $where;
                return 4;
            }
        );

        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getConnection')->willReturn($connection);
        $events->method('getMainTable')->willReturn('panth_error_event');
        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($connection);
        $groups->method('getMainTable')->willReturn('panth_error_group');

        return new Cleanup($config, $events, $groups, $this->createStub(LoggerInterface::class));
    }
}
