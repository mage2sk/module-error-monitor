<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\Collection;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\Regrouper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegrouperTest extends TestCase
{
    private array $updates = [];

    private array $deletes = [];

    private array $tx = [];

    private ?\Throwable $deleteException = null;

    private array $warnings = [];

    private function rows(): array
    {
        $fp = new Fingerprinter();
        $stable = $fp->fingerprint('js', 'TypeError', 'TypeError: stable', '', null);
        return [
            ['group_id' => 1, 'fingerprint' => 'old1', 'error_type' => '', 'source' => 'php',
                'message' => 'RuntimeException: boom 12', 'file' => null, 'line' => null],
            ['group_id' => 2, 'fingerprint' => 'old2', 'error_type' => 'RuntimeException', 'source' => 'php',
                'message' => 'RuntimeException: boom 99', 'file' => null, 'line' => null],
            ['group_id' => 3, 'fingerprint' => $stable, 'error_type' => 'TypeError', 'source' => 'js',
                'message' => 'TypeError: stable', 'file' => null, 'line' => null],
            ['group_id' => 4, 'fingerprint' => 'old4', 'error_type' => 'exception', 'source' => 'php',
                'message' => 'LogicException: other', 'file' => '/x/A.php', 'line' => '7'],
        ];
    }

    private function regrouper(array $rows): Regrouper
    {
        $items = array_map(static fn (array $row) => new DataObject($row), $rows);
        $collection = $this->createStub(Collection::class);
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('quoteIdentifier')->willReturnCallback(static fn ($v) => '`' . $v . '`');
        $conn->method('beginTransaction')->willReturnCallback(function () use ($conn) {
            $this->tx[] = 'begin';
            return $conn;
        });
        $conn->method('commit')->willReturnCallback(function () use ($conn) {
            $this->tx[] = 'commit';
            return $conn;
        });
        $conn->method('rollBack')->willReturnCallback(function () use ($conn) {
            $this->tx[] = 'rollback';
            return $conn;
        });
        $conn->method('fetchRow')->willReturn([
            'oc' => '17', 'first_seen' => '2026-01-01 00:00:00', 'last_seen' => '2026-02-01 00:00:00',
            'ec' => '3', 'led' => '2026-01-15',
        ]);
        $conn->method('update')->willReturnCallback(function ($table, array $bind, $where): int {
            $this->updates[] = [$table, $bind, $where];
            return $table === 'panth_error_event' ? 4 : 1;
        });
        $conn->method('delete')->willReturnCallback(function ($table, $where): int {
            if ($this->deleteException !== null) {
                throw $this->deleteException;
            }
            $this->deletes[] = [$table, $where];
            return 1;
        });

        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($conn);
        $groups->method('getMainTable')->willReturn('panth_error_group');
        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getMainTable')->willReturn('panth_error_event');

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = $message;
        });

        return new Regrouper($groups, $events, $factory, new Fingerprinter(), $logger);
    }

    public function testPlanCountsUpdatesMergesAndDeletes(): void
    {
        $plan = $this->regrouper($this->rows())->plan();
        $this->assertSame(
            ['scanned' => 4, 'would_update' => 2, 'would_merge' => 1, 'would_delete' => 1],
            $plan
        );
        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->tx);
    }

    public function testRegroupAllMergesAndUpdates(): void
    {
        $stats = $this->regrouper($this->rows())->regroupAll();

        $this->assertSame(
            ['scanned' => 4, 'updated' => 1, 'merged' => 1, 'deleted_groups' => 1, 'events_moved' => 4],
            $stats
        );
        $this->assertSame(['begin', 'commit'], $this->tx);

        $fp = new Fingerprinter();
        $canonicalFp = $fp->fingerprint('php', 'RuntimeException', 'RuntimeException: boom 12', '', null);

        [$table, $bind, $where] = $this->updates[0];
        $this->assertSame('panth_error_event', $table);
        $this->assertSame(['group_id' => 1], $bind);
        $this->assertSame(['group_id IN (?)' => [2]], $where);

        $this->assertSame([['panth_error_group', ['group_id IN (?)' => [2]]]], $this->deletes);

        [$table, $bind, $where] = $this->updates[1];
        $this->assertSame('panth_error_group', $table);
        $this->assertSame(['group_id = ?' => 1], $where);
        $this->assertSame($canonicalFp, $bind['fingerprint']);
        $this->assertSame('RuntimeException', $bind['error_type']);
        $this->assertSame(17, $bind['occurrence_count']);
        $this->assertSame(3, $bind['emailed_count']);
        $this->assertSame('2026-01-01 00:00:00', $bind['first_seen_at']);
        $this->assertSame('2026-02-01 00:00:00', $bind['last_seen_at']);
        $this->assertSame('2026-01-15', $bind['last_emailed_date']);

        [$table, $bind, $where] = $this->updates[2];
        $this->assertSame('panth_error_group', $table);
        $this->assertSame(['group_id = ?' => 4], $where);
        $this->assertSame('LogicException', $bind['error_type']);
        $this->assertSame($fp->fingerprint('php', 'LogicException', 'LogicException: other', '/x/A.php', 7), $bind['fingerprint']);
        $this->assertCount(3, $this->updates);
    }

    public function testEmptyTableDoesNothing(): void
    {
        $stats = $this->regrouper([])->regroupAll();
        $this->assertSame(
            ['scanned' => 0, 'updated' => 0, 'merged' => 0, 'deleted_groups' => 0, 'events_moved' => 0],
            $stats
        );
        $this->assertSame([], $this->tx);
    }

    public function testFailureRollsBackAndRethrows(): void
    {
        $this->deleteException = new \RuntimeException('lock wait timeout');
        $regrouper = $this->regrouper($this->rows());
        try {
            $regrouper->regroupAll();
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('lock wait timeout', $e->getMessage());
        }
        $this->assertSame(['begin', 'rollback'], $this->tx);
        $this->assertSame(['[PanthErrorMonitor] regroup rolled back: lock wait timeout'], $this->warnings);
    }
}
