<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\DeepTest;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\ErrorMonitor\Console\Command\RefingerprintCommand;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\PathMapper;
use Panth\ErrorMonitor\Service\Refingerprinter;
use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\ErrorMonitor\Service\RefingerprintRolledBackException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Deep test chunk 4: panth:error-monitor:refingerprint + Service\Refingerprinter.
 *
 * Runs the real service against an in-memory fake of the two tables that enforces the
 * UNIQUE(fingerprint) key, the event -> group FK with ON DELETE CASCADE and transactions
 * (snapshot / restore). The real panth_error_* tables are never touched.
 */
class RefingerprintDeepTest extends TestCase
{
    private const G = 'panth_error_group';
    private const E = 'panth_error_event';

    /** @var array<int, array> */
    private array $groups = [];
    /** @var array<int, int> event_id => group_id */
    private array $events = [];
    private ?array $snapshot = null;
    private int $fetchAllCalls = 0;
    private int $eventUpdates = 0;
    private ?int $failOnEventUpdate = null;
    private ?\Closure $onBegin = null;
    private bool $limitUsed = false;
    private bool $forUpdateUsed = false;
    private array $log = [];
    private ?array $scopeValues = null;

    // ------------------------------------------------------------------ fake DB

    private function service(): Refingerprinter
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('limit')->willReturnCallback(function () use ($select) {
            $this->limitUsed = true;
            return $select;
        });
        $select->method('limitPage')->willReturnCallback(function () use ($select) {
            $this->limitUsed = true;
            return $select;
        });
        $select->method('forUpdate')->willReturnCallback(function () use ($select) {
            $this->forUpdateUsed = true;
            return $select;
        });

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchAll')->willReturnCallback(function () {
            $this->fetchAllCalls++;
            return array_values($this->groups);
        });
        $conn->method('beginTransaction')->willReturnCallback(function () use ($conn) {
            // A concurrent writer that commits just before our transaction starts.
            if ($this->onBegin !== null) {
                ($this->onBegin)();
            }
            $this->snapshot = [$this->groups, $this->events];
            $this->log[] = 'begin';
            return $conn;
        });
        $conn->method('commit')->willReturnCallback(function () use ($conn) {
            $this->snapshot = null;
            $this->log[] = 'commit';
            return $conn;
        });
        $conn->method('rollBack')->willReturnCallback(function () use ($conn) {
            [$this->groups, $this->events] = $this->snapshot;
            $this->snapshot = null;
            $this->log[] = 'rollback';
            return $conn;
        });
        $conn->method('update')->willReturnCallback(fn ($t, $b, $w = '') => $this->fakeUpdate($t, $b, $w));
        $conn->method('delete')->willReturnCallback(fn ($t, $w = '') => $this->fakeDelete($t, $w));
        $conn->method('quoteIdentifier')->willReturnCallback(static fn ($v) => '`' . $v . '`');
        $conn->method('fetchOne')->willReturnCallback(fn ($sql, $bind = []) => $this->fakeCount((string)$sql, (array)$bind));
        $conn->method('query')->willReturnCallback(function ($sql, $bind = []) {
            $this->fakeQuery((string)$sql, (array)$bind);
            return null;
        });

        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($conn);
        $groups->method('getMainTable')->willReturn(self::G);
        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getMainTable')->willReturn(self::E);
        $events->method('getConnection')->willReturn($conn);

        $scope = null;
        if ($this->scopeValues !== null) {
            $scope = $this->createStub(ScopeConfigInterface::class);
            $scope->method('getValue')->willReturnCallback(fn ($path) => $this->scopeValues[$path] ?? null);
        }

        return new Refingerprinter(
            $groups,
            $events,
            new Fingerprinter(),
            new PathMapper(),
            $this->createStub(LoggerInterface::class),
            $scope
        );
    }

    private function fakeCount(string $sql, array $bind): int
    {
        if (preg_match('/group_id IN \(([\d,]+)\)/', $sql, $m) === 1) {
            $ids = array_map('intval', explode(',', $m[1]));
        } else {
            $ids = [(int)($bind[0] ?? 0)];
        }
        return count(array_filter($this->events, static fn ($g) => in_array($g, $ids, true)));
    }

    private function fakeQuery(string $sql, array $bind): void
    {
        if (preg_match('/^DELETE FROM .* ORDER BY created_at (ASC|DESC), event_id (?:ASC|DESC) LIMIT (\d+)$/', $sql, $m) !== 1) {
            return;
        }
        $ids = array_keys(array_filter($this->events, static fn ($g) => $g === (int)$bind[0]));
        sort($ids);
        if ($m[1] === 'DESC') {
            $ids = array_reverse($ids);
        }
        foreach (array_slice($ids, 0, (int)$m[2]) as $eventId) {
            unset($this->events[$eventId]);
        }
    }

    private function ids(array $where): array
    {
        $ids = [];
        foreach ($where as $cond => $value) {
            if ($cond === 'group_id IN (?)') {
                $ids = array_map('intval', (array)$value);
            } elseif ($cond === 'group_id = ?') {
                $ids = [(int)$value];
            } else {
                throw new \LogicException('Unexpected where: ' . $cond);
            }
        }
        return $ids;
    }

    private function fakeUpdate(string $table, array $bind, $where): int
    {
        $ids = $this->ids((array)$where);
        $n = 0;
        if ($table === self::E) {
            $this->eventUpdates++;
            if ($this->failOnEventUpdate === $this->eventUpdates) {
                throw new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
            $target = (int)$bind['group_id'];
            if (!isset($this->groups[$target])) {
                throw new \RuntimeException('SQLSTATE[23000]: 1452 FK fails');
            }
            foreach ($this->events as $eid => $gid) {
                if (in_array($gid, $ids, true)) {
                    $this->events[$eid] = $target;
                    $n++;
                }
            }
            return $n;
        }
        foreach ($ids as $id) {
            if (!isset($this->groups[$id])) {
                continue;
            }
            if (isset($bind['fingerprint'])) {
                foreach ($this->groups as $otherId => $other) {
                    if ($otherId !== $id && $other['fingerprint'] === $bind['fingerprint']) {
                        throw new \RuntimeException(
                            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key"
                            . " 'PANTH_ERROR_GROUP_FINGERPRINT' (group $id vs $otherId)"
                        );
                    }
                }
            }
            $this->groups[$id] = array_merge($this->groups[$id], $bind);
            $n++;
        }
        return $n;
    }

    private function fakeDelete(string $table, $where): int
    {
        $ids = $this->ids((array)$where);
        $n = 0;
        foreach ($ids as $id) {
            if (isset($this->groups[$id])) {
                unset($this->groups[$id]);
                $n++;
                foreach ($this->events as $eid => $gid) {
                    if ($gid === $id) {
                        unset($this->events[$eid]); // ON DELETE CASCADE
                    }
                }
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ fixtures

    private function fp(array $g): string
    {
        return (new Fingerprinter())->fingerprint($g['source'], $g['error_type'], $g['message'], $g['file'], $g['line']);
    }

    private function addGroup(int $id, array $overrides = [], ?string $fingerprint = null, int $events = 2): array
    {
        $row = array_merge([
            'group_id' => $id,
            'source' => 'php',
            'severity' => 'error',
            'error_type' => 'RuntimeException',
            'message' => 'Boom happened',
            'file' => '/var/www/html/app/code/A/B.php',
            'line' => 10,
            'status' => ErrorGroup::STATUS_NEW,
            'occurrence_count' => 1,
            'first_seen_at' => '2026-10-01 00:00:00',
            'last_seen_at' => '2026-10-01 00:00:00',
            'emailed_count' => 0,
            'last_emailed_date' => null,
        ], $overrides);
        $row['fingerprint'] = $fingerprint ?? hash('sha256', 'stale-' . $id);
        $this->groups[$id] = $row;
        $next = $this->events === [] ? 1 : max(array_keys($this->events)) + 1;
        for ($i = 0; $i < $events; $i++) {
            $this->events[$next + $i] = $id;
        }
        return $row;
    }

    private function eventsOf(int $groupId): int
    {
        return count(array_filter($this->events, static fn ($g) => $g === $groupId));
    }

    /** Four duplicates of one error with stale fingerprints and mixed aggregates. */
    private function fourDuplicates(): void
    {
        $this->addGroup(10, [
            'status' => ErrorGroup::STATUS_RESOLVED, 'severity' => 'warning', 'occurrence_count' => 5,
            'first_seen_at' => '2026-09-01 00:00:00', 'last_seen_at' => '2026-09-02 00:00:00',
            'emailed_count' => 2, 'last_emailed_date' => '2026-09-02',
        ], null, 3);
        $this->addGroup(11, [
            'status' => ErrorGroup::STATUS_NEW, 'severity' => 'critical', 'occurrence_count' => 7,
            'first_seen_at' => '2026-09-05 00:00:00', 'last_seen_at' => '2026-10-28 10:00:00',
            'emailed_count' => 1, 'last_emailed_date' => '2026-10-28',
        ], null, 4);
        $this->addGroup(12, [
            'status' => ErrorGroup::STATUS_IGNORED, 'severity' => 'notice', 'occurrence_count' => 11,
            'first_seen_at' => '2026-08-15 00:00:00', 'last_seen_at' => '2026-08-16 00:00:00',
        ], null, 1);
        $this->addGroup(13, [
            'status' => ErrorGroup::STATUS_RESOLVED, 'occurrence_count' => 2,
            'first_seen_at' => '2026-10-20 00:00:00', 'last_seen_at' => '2026-10-21 00:00:00',
        ], null, 2);
        // An unrelated group that must be left alone.
        $other = ['message' => 'Completely different', 'occurrence_count' => 9];
        $this->addGroup(99, $other, $this->fp(array_merge($this->groups[10], $other)), 5);
    }

    // ------------------------------------------------------------------ tests

    public function testDryRunChangesNothingAndReportsExactPlannedMerges(): void
    {
        $this->fourDuplicates();
        $before = [$this->groups, $this->events];

        $stats = $this->service()->run(true);

        self::assertSame($before, [$this->groups, $this->events], 'dry-run must not write');
        self::assertNotContains('begin', $this->log, 'dry-run must not open a transaction');
        // Oldest first_seen (group 12, 2026-08-15) survives; others listed in first_seen order.
        self::assertSame([12 => [10, 11, 13]], $stats['merges']);
        self::assertSame(1, $stats['merged_groups']);
        self::assertSame(3, $stats['deleted_groups']);
        self::assertSame(5, $stats['scanned']);
        self::assertTrue($stats['dry_run']);
        self::assertSame(3 + 4 + 2, $stats['events_moved'], 'dry-run reports the real number of events to move');
    }

    public function testMergeOfNDuplicatesRepointsEventsAndCombinesAggregates(): void
    {
        $this->fourDuplicates();
        $expectedFp = $this->fp($this->groups[12]);

        $stats = $this->service()->run(false);

        self::assertSame(['begin', 'commit'], $this->log);
        self::assertSame([12, 99], array_keys($this->groups));
        $s = $this->groups[12];
        self::assertSame($expectedFp, $s['fingerprint']);
        self::assertSame(5 + 7 + 11 + 2, $s['occurrence_count']);
        self::assertSame('2026-08-15 00:00:00', $s['first_seen_at']);
        self::assertSame('2026-10-28 10:00:00', $s['last_seen_at']);
        self::assertSame('critical', $s['severity']);
        self::assertSame(ErrorGroup::STATUS_NEW, $s['status'], 'any open member keeps the merged group open');
        self::assertSame(2, $s['emailed_count'], 'emailed_count = max, not sum');
        self::assertSame('2026-10-28', $s['last_emailed_date'], 'latest emailed date kept: no duplicate mail today');
        self::assertSame(3 + 4 + 1 + 2, $this->eventsOf(12));
        self::assertSame(5, $this->eventsOf(99));
        self::assertSame(15, count($this->events), 'no event lost');
        self::assertSame(3 + 4 + 2, $stats['events_moved']);
        self::assertSame(9, $this->groups[99]['occurrence_count']);
    }

    public function testResolvedMergedWithOpenStaysOpenAndIgnoredBeatsResolved(): void
    {
        $this->addGroup(1, ['status' => ErrorGroup::STATUS_RESOLVED, 'first_seen_at' => '2026-01-01 00:00:00']);
        $this->addGroup(2, ['status' => ErrorGroup::STATUS_NEW, 'first_seen_at' => '2026-02-01 00:00:00']);
        $this->service()->run(false);
        self::assertSame(ErrorGroup::STATUS_NEW, $this->groups[1]['status']);

        $this->groups = $this->events = [];
        $this->log = [];
        $this->addGroup(1, ['status' => ErrorGroup::STATUS_RESOLVED, 'first_seen_at' => '2026-01-01 00:00:00']);
        $this->addGroup(2, ['status' => ErrorGroup::STATUS_IGNORED, 'first_seen_at' => '2026-02-01 00:00:00']);
        $this->service()->run(false);
        self::assertSame(ErrorGroup::STATUS_IGNORED, $this->groups[1]['status']);
    }

    public function testSurvivorTakesFingerprintCurrentlyHeldByAPeerWithoutDuplicateKey(): void
    {
        // Peer 2 already carries the correct (new) fingerprint; older survivor 1 has a stale one.
        $base = $this->addGroup(1, ['first_seen_at' => '2026-01-01 00:00:00']);
        $this->addGroup(2, ['first_seen_at' => '2026-05-01 00:00:00'], $this->fp($base));

        $this->service()->run(false);

        self::assertSame([1], array_keys($this->groups));
        self::assertSame($this->fp($base), $this->groups[1]['fingerprint']);
        self::assertSame(4, $this->eventsOf(1));
    }

    public function testFingerprintSwapBetweenTwoGroupsIsOrderedThroughPlaceholders(): void
    {
        $alpha = ['message' => 'Alpha failure'];
        $beta = ['message' => 'Beta failure'];
        $fpAlpha = $this->fp(array_merge($this->defaults(), $alpha));
        $fpBeta = $this->fp(array_merge($this->defaults(), $beta));
        // Each group holds the other's correct fingerprint: a naive one-pass update hits 1062.
        $this->addGroup(1, $alpha, $fpBeta);
        $this->addGroup(2, $beta, $fpAlpha);

        $stats = $this->service()->run(false);

        self::assertSame(['begin', 'commit'], $this->log);
        self::assertSame($fpAlpha, $this->groups[1]['fingerprint']);
        self::assertSame($fpBeta, $this->groups[2]['fingerprint']);
        self::assertSame([], $stats['merges']);
        self::assertSame(2, $stats['updated_groups']);
    }

    public function testSecondRunIsANoOp(): void
    {
        $this->fourDuplicates();
        $this->addGroup(50, [
            'error_type' => 'main',
            'message' => 'PHP Warning:  Undefined array key "x" in /a.php',
            'file' => '/var/www/html/var/view_preprocessed/pub/static/app/design/frontend/V/t/x.phtml',
        ]);
        $service = $this->service();
        $service->run(false);
        $afterFirst = [$this->groups, $this->events];
        $this->log = [];

        $stats = $service->run(false);

        self::assertSame($afterFirst, [$this->groups, $this->events]);
        self::assertSame([], $stats['merges']);
        self::assertSame(0, $stats['updated_groups']);
        self::assertSame([], $this->log, 'idempotent run must not even open a transaction');
    }

    public function testMidwayFailureRollsBackEverything(): void
    {
        $this->fourDuplicates();
        $this->addGroup(20, ['message' => 'Second dup set', 'first_seen_at' => '2026-01-01 00:00:00']);
        $this->addGroup(21, ['message' => 'Second dup set', 'first_seen_at' => '2026-01-02 00:00:00']);
        $before = [$this->groups, $this->events];
        $this->failOnEventUpdate = 2; // first merge already applied when this fires

        try {
            $this->service()->run(false);
            self::fail('Expected the failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf(RefingerprintRolledBackException::class, $e);
            self::assertStringContainsString('gone away', $e->getMessage());
        }
        self::assertSame(['begin', 'rollback'], $this->log);
        self::assertSame($before, [$this->groups, $this->events]);
    }

    public function testConcurrentGroupWithTargetFingerprintIsMergedInTheSameRun(): void
    {
        $base = $this->addGroup(1, ['first_seen_at' => '2026-01-01 00:00:00']);
        $this->addGroup(2, ['first_seen_at' => '2026-02-01 00:00:00']);
        $this->onBegin = function () use ($base): void {
            $this->onBegin = null;
            $this->addGroup(3, ['first_seen_at' => '2026-10-29 09:00:00'], $this->fp($base), 1);
        };

        $stats = $this->service()->run(false);

        self::assertSame(['begin', 'commit'], $this->log);
        self::assertSame([1], array_keys($this->groups), 'the plan is rebuilt from rows locked inside the transaction');
        self::assertSame(5, $this->eventsOf(1));
        self::assertSame([1 => [2, 3]], $stats['merges']);
    }

    /**
     * DEFECT (race): groups are read with a plain SELECT before beginTransaction() and the survivor is
     * then written with ABSOLUTE values (occurrence_count, last_seen_at, status) from that snapshot.
     * Concurrent ErrorRecorder upserts in between (occurrence_count + n, last_seen_at = now,
     * status RESOLVED -> NEW) are overwritten: counts lost, last_seen regresses, a reopened error is
     * silently re-resolved. Expected to FAIL until the read is done inside the transaction with
     * SELECT ... FOR UPDATE (or the aggregates are computed in SQL relative to current values).
     */
    public function testConcurrentRecorderUpdatesOnSurvivorAreNotLost(): void
    {
        $base = $this->defaults();
        // Survivor already has the correct fingerprint, so 1.7.0 captures upsert straight into it.
        $this->addGroup(1, [
            'status' => ErrorGroup::STATUS_RESOLVED, 'occurrence_count' => 10,
            'first_seen_at' => '2026-01-01 00:00:00', 'last_seen_at' => '2026-10-01 00:00:00',
        ], $this->fp($base));
        $this->addGroup(2, [
            'status' => ErrorGroup::STATUS_RESOLVED, 'occurrence_count' => 4,
            'first_seen_at' => '2026-02-01 00:00:00', 'last_seen_at' => '2026-10-02 00:00:00',
        ]);
        $this->onBegin = function (): void {
            $this->groups[1]['occurrence_count'] += 3;
            $this->groups[1]['last_seen_at'] = '2026-10-29 12:00:00';
            $this->groups[1]['status'] = ErrorGroup::STATUS_NEW; // reopened by the recorder
        };

        $this->service()->run(false);

        self::assertSame(ErrorGroup::STATUS_NEW, $this->groups[1]['status'], 'reopened error was re-resolved');
        self::assertSame('2026-10-29 12:00:00', $this->groups[1]['last_seen_at'], 'last_seen_at regressed');
        self::assertSame(10 + 4 + 3, $this->groups[1]['occurrence_count'], 'concurrent occurrences lost');
    }

    public function testSurvivorIsNotPrunedWithoutConfiguration(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->addGroup($i, ['first_seen_at' => "2026-0$i-01 00:00:00"], null, 50);
        }
        $this->service()->run(false);
        self::assertSame(150, $this->eventsOf(1));
        self::assertSame(150, count($this->events));
    }

    public function testSurvivorIsTrimmedToTheCapKeepingTheOldestRowsByDefault(): void
    {
        $this->scopeValues = ['panth_errormonitor/general/max_events_per_group' => '50'];
        for ($i = 1; $i <= 3; $i++) {
            $this->addGroup($i, ['first_seen_at' => "2026-0$i-01 00:00:00"], null, 50);
        }
        $stats = $this->service()->run(false);
        self::assertSame(50, $this->eventsOf(1));
        self::assertSame(range(1, 50), array_keys($this->events), 'oldest mode keeps the first 50 rows');
        self::assertSame(100, $stats['events_trimmed']);
    }

    public function testSurvivorIsTrimmedToTheCapKeepingTheNewestRowsInNewestMode(): void
    {
        $this->scopeValues = [
            'panth_errormonitor/general/max_events_per_group' => '50',
            'panth_errormonitor/general/event_cap_mode' => 'newest',
        ];
        for ($i = 1; $i <= 3; $i++) {
            $this->addGroup($i, ['first_seen_at' => "2026-0$i-01 00:00:00"], null, 50);
        }
        $this->service()->run(false);
        self::assertSame(50, $this->eventsOf(1));
        self::assertSame(range(101, 150), array_keys($this->events), 'newest mode keeps the last 50 rows');
    }

    public function testDryRunReportsTheSameTrimCountAsTheRealRun(): void
    {
        foreach (['oldest', 'newest'] as $mode) {
            $this->groups = $this->events = [];
            $this->log = [];
            $this->scopeValues = [
                'panth_errormonitor/general/max_events_per_group' => '40',
                'panth_errormonitor/general/event_cap_mode' => $mode,
            ];
            $this->addGroup(1, ['first_seen_at' => '2026-01-01 00:00:00'], null, 30);
            $this->addGroup(2, ['first_seen_at' => '2026-02-01 00:00:00'], null, 25);
            $this->addGroup(3, ['message' => 'Unrelated'], null, 60);
            $dry = $this->service()->run(true);
            self::assertSame(15, $dry['events_trimmed'], $mode);
            self::assertSame(25, $dry['events_moved'], $mode);
            $real = $this->service()->run(false);
            self::assertSame($dry['events_trimmed'], $real['events_trimmed'], $mode);
            self::assertSame(40, $this->eventsOf(1), $mode);
            self::assertSame(60, $this->eventsOf(3), 'groups that are not merged are not trimmed');
        }
    }

    public function testGroupsAreReloadedWithForUpdateInsideTheTransaction(): void
    {
        $this->fourDuplicates();
        $this->service()->run(false);
        self::assertSame(2, $this->fetchAllCalls, 'one plain read for the plan, one locked read inside the transaction');
        self::assertFalse($this->limitUsed);
        self::assertTrue($this->forUpdateUsed);
        self::assertSame(1, count(array_keys($this->log, 'begin', true)));
    }

    public function testStoredGraphQlClientErrorsAreReclassifiedAsNotice(): void
    {
        $this->addGroup(1, [
            'error_type' => 'GraphQL\\Error\\Error',
            'message' => 'Cannot query field "stock_status" on type "ProductInterface".',
            'severity' => 'error',
        ]);
        $this->addGroup(2, [
            'error_type' => 'Magento\\Framework\\GraphQl\\Exception\\GraphQlInputException',
            'message' => 'The region_id does not match the selected country or region',
            'severity' => 'error',
        ]);
        $this->addGroup(3, [
            'error_type' => 'GraphQL\\Error\\Error',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
            'severity' => 'error',
        ]);
        $stats = $this->service()->run(false);
        self::assertSame('notice', $this->groups[1]['severity']);
        self::assertSame('notice', $this->groups[2]['severity']);
        self::assertSame('error', $this->groups[3]['severity'], 'server faults keep their severity');
        self::assertSame(2, $stats['reclassified_groups']);

        $this->log = [];
        $this->scopeValues = ['panth_errormonitor/php_capture/graphql_client_errors' => 'record'];
        $this->groups[1]['severity'] = 'error';
        $stats = $this->service()->run(false);
        self::assertSame('error', $this->groups[1]['severity'], 'record mode keeps GraphQL client errors as errors');
        self::assertSame(0, $stats['reclassified_groups']);
    }

    public function testPlanOnFiftyThousandGroupsStaysWithinMemoryAndTime(): void
    {
        $rows = [];
        $msg = str_repeat('x', 1000);
        for ($i = 1; $i <= 50000; $i++) {
            $rows[] = array_merge($this->defaults(), [
                'group_id' => $i,
                'fingerprint' => 'stale',
                'message' => $this->word($i % 25000) . ' ' . $msg, // pairs of duplicates (digits normalised, tail truncated)
                'status' => 0,
                'occurrence_count' => 1,
                'first_seen_at' => '2026-10-01 00:00:00',
                'last_seen_at' => '2026-10-01 00:00:00',
                'emailed_count' => 0,
                'last_emailed_date' => null,
            ]);
        }
        $memBefore = memory_get_usage();
        $t = microtime(true);
        $plan = $this->service()->plan($rows);
        $elapsed = microtime(true) - $t;
        $mem = memory_get_peak_usage() - $memBefore;

        self::assertCount(25000, $plan['merges']);
        self::assertLessThan(60.0, $elapsed);
        self::assertLessThan(1024 * 1024 * 1024, $mem);
        fwrite(STDERR, sprintf("\n[deeptest] plan(50k groups): %.2fs, ~%d MB\n", $elapsed, $mem / 1048576));
    }

    // ------------------------------------------------------------------ command

    private function tester(?\Throwable $fail = null, array $merges = []): CommandTester
    {
        $service = $this->createStub(Refingerprinter::class);
        if ($fail !== null) {
            $service->method('run')->willThrowException($fail);
        } else {
            $service->method('run')->willReturnCallback(fn (bool $dry) => [
                'dry_run' => $dry, 'scanned' => 9, 'merged_groups' => count($merges),
                'deleted_groups' => array_sum(array_map('count', $merges)), 'updated_groups' => 4,
                'events_moved' => $dry ? 0 : 12, 'merges' => $merges,
            ]);
        }
        return new CommandTester(new RefingerprintCommand($service));
    }

    public function testCommandNameAndDryRunOptionMatchReadme(): void
    {
        $cmd = new RefingerprintCommand($this->createStub(Refingerprinter::class));
        self::assertSame('panth:errormonitor:refingerprint', $cmd->getName());
        self::assertSame(['panth:error-monitor:refingerprint'], $cmd->getAliases());
        self::assertTrue($cmd->getDefinition()->hasOption('dry-run'));
        self::assertFalse($cmd->getDefinition()->getOption('dry-run')->acceptValue());
    }

    public function testCommandDryRunOutputAndExitCode(): void
    {
        $t = $this->tester(null, [12 => [10, 11, 13]]);
        self::assertSame(Command::SUCCESS, $t->execute(['--dry-run' => true]));
        $out = $t->getDisplay();
        self::assertStringContainsString('[dry-run] scanned=9', $out);
        self::assertStringContainsString('group #12 would absorb #10, #11, #13', $out);
    }

    public function testCommandListsAtMostFiftyMergesThenSummarises(): void
    {
        $merges = [];
        for ($i = 1; $i <= 53; $i++) {
            $merges[$i * 10] = [$i * 10 + 1];
        }
        $t = $this->tester(null, $merges);
        self::assertSame(Command::SUCCESS, $t->execute([]));
        $out = $t->getDisplay();
        self::assertSame(50, substr_count($out, ' absorbed '));
        self::assertStringContainsString('... 3 more', $out);
    }

    public function testCommandFailureReturnsNonZero(): void
    {
        $t = $this->tester(new RefingerprintRolledBackException('Duplicate entry'));
        self::assertSame(Command::FAILURE, $t->execute([]));
        self::assertStringContainsString('rolled back: Duplicate entry', $t->getDisplay());

        $t = $this->tester(new \RuntimeException('Table does not exist'));
        self::assertSame(Command::FAILURE, $t->execute([]));
        self::assertStringNotContainsString('rolled back', $t->getDisplay());
        self::assertStringContainsString('Table does not exist', $t->getDisplay());
    }

    private function word(int $n): string
    {
        $w = '';
        do {
            $w .= chr(97 + $n % 26);
            $n = intdiv($n, 26);
        } while ($n > 0);
        return $w;
    }

    private function defaults(): array
    {
        return [
            'source' => 'php', 'severity' => 'error', 'error_type' => 'RuntimeException',
            'message' => 'Boom happened', 'file' => '/var/www/html/app/code/A/B.php', 'line' => 10,
        ];
    }
}
