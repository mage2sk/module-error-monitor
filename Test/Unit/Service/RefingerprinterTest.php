<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\PathMapper;
use Panth\ErrorMonitor\Service\Refingerprinter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RefingerprinterTest extends TestCase
{
    private array $rows = [];

    private array $calls = [];

    private ?\Throwable $failOnDelete = null;

    private function service(): Refingerprinter
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchAll')->willReturnCallback(fn () => $this->rows);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $conn->method($method)->willReturnCallback(function () use ($method, $conn) {
                $this->calls[] = [$method];
                return $conn;
            });
        }
        $conn->method('update')->willReturnCallback(function ($table, $bind, $where): int {
            $this->calls[] = ['update', $table, $bind, $where];
            return $table === 'panth_error_event' ? 7 : 1;
        });
        $conn->method('delete')->willReturnCallback(function ($table, $where): int {
            if ($this->failOnDelete !== null) {
                throw $this->failOnDelete;
            }
            $this->calls[] = ['delete', $table, $where];
            return 1;
        });

        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($conn);
        $groups->method('getMainTable')->willReturn('panth_error_group');
        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getMainTable')->willReturn('panth_error_event');

        return new Refingerprinter(
            $groups,
            $events,
            new Fingerprinter(),
            new PathMapper(),
            $this->createStub(LoggerInterface::class)
        );
    }

    private function sampleGroups(): array
    {
        $fp = new Fingerprinter();
        $stable = [
            'group_id' => 7,
            'source' => 'php',
            'severity' => 'error',
            'error_type' => 'RuntimeException',
            'message' => 'Unrelated failure',
            'file' => '/var/www/html/app/code/A/B.php',
            'line' => 3,
            'status' => ErrorGroup::STATUS_NEW,
            'occurrence_count' => 5,
            'first_seen_at' => '2026-07-01 00:00:00',
            'last_seen_at' => '2026-10-01 00:00:00',
            'emailed_count' => 0,
            'last_emailed_date' => null,
        ];
        $stable['fingerprint'] = $fp->fingerprint('php', 'RuntimeException', 'Unrelated failure', $stable['file'], 3);

        return [
            [
                'group_id' => 1338,
                'fingerprint' => 'old-safari',
                'source' => 'js',
                'severity' => 'critical',
                'error_type' => 'TypeError',
                'message' => "TypeError: undefined is not an object (evaluating 'storage.remove')",
                'file' => 'https://shop.test/static/version1793000663/frontend/V/t/en_US/js/jquery.storageapi.min.js',
                'line' => 1,
                'status' => ErrorGroup::STATUS_NEW,
                'occurrence_count' => 50,
                'first_seen_at' => '2026-07-05 00:00:00',
                'last_seen_at' => '2026-10-27 18:48:58',
                'emailed_count' => 5,
                'last_emailed_date' => '2026-10-27',
            ],
            [
                'group_id' => 251,
                'fingerprint' => 'old-chrome',
                'source' => 'js',
                'severity' => 'error',
                'error_type' => 'TypeError',
                'message' => "Uncaught TypeError: Cannot read properties of undefined (reading 'remove')",
                'file' => 'https://shop.test/static/version1785394171/frontend/V/t/en_US/Magento_Customer/js/customer-data.min.js',
                'line' => 1,
                'status' => ErrorGroup::STATUS_RESOLVED,
                'occurrence_count' => 100,
                'first_seen_at' => '2026-07-02 00:00:00',
                'last_seen_at' => '2026-10-20 00:00:00',
                'emailed_count' => 3,
                'last_emailed_date' => '2026-10-20',
            ],
            [
                'group_id' => 1286,
                'fingerprint' => 'old-firefox',
                'source' => 'js',
                'severity' => 'warning',
                'error_type' => 'TypeError',
                'message' => 'TypeError: can\'t access property "remove", storage is undefined',
                'file' => null,
                'line' => null,
                'status' => ErrorGroup::STATUS_IGNORED,
                'occurrence_count' => 10,
                'first_seen_at' => null,
                'last_seen_at' => '2026-09-01 00:00:00',
                'emailed_count' => 0,
                'last_emailed_date' => null,
            ],
            $stable,
        ];
    }

    public function testDuplicatesMergeIntoEarliestGroupWithCombinedAggregates(): void
    {
        $plan = $this->service()->plan($this->sampleGroups());

        $this->assertSame(4, $plan['scanned']);
        $this->assertSame([251 => [1338, 1286]], $plan['merges']);
        $update = $plan['updates'][251];
        $this->assertSame(160, $update['occurrence_count']);
        $this->assertSame('2026-10-27 18:48:58', $update['last_seen_at']);
        $this->assertSame('critical', $update['severity']);
        $this->assertSame(ErrorGroup::STATUS_NEW, $update['status']);
        $this->assertSame(5, $update['emailed_count']);
        $this->assertSame('2026-10-27', $update['last_emailed_date']);
        $this->assertArrayNotHasKey('first_seen_at', $update);
        $this->assertNotSame('old-chrome', $update['fingerprint']);
        $this->assertArrayNotHasKey(7, $plan['updates']);
    }

    public function testStatusPriorityPrefersIgnoredOverResolved(): void
    {
        $aggregates = $this->service()->mergedAggregates([
            ['status' => ErrorGroup::STATUS_RESOLVED, 'severity' => 'error', 'occurrence_count' => 1],
            ['status' => ErrorGroup::STATUS_IGNORED, 'severity' => 'notice', 'occurrence_count' => 2],
        ]);
        $this->assertSame(ErrorGroup::STATUS_IGNORED, $aggregates['status']);
        $this->assertSame('error', $aggregates['severity']);
        $this->assertSame(3, $aggregates['occurrence_count']);
        $this->assertNull($aggregates['first_seen_at']);
    }

    public function testPlanIsIdempotent(): void
    {
        $service = $this->service();
        $groups = $this->sampleGroups();
        $plan = $service->plan($groups);

        $after = [];
        foreach ($groups as $group) {
            $id = (int)$group['group_id'];
            if (in_array($id, $plan['merges'][251], true)) {
                continue;
            }
            $after[] = array_merge($group, $plan['updates'][$id] ?? []);
        }
        $second = $service->plan($after);
        $this->assertSame([], $second['merges']);
        $this->assertSame([], $second['updates']);
    }

    public function testPreprocessedFileIsRewritten(): void
    {
        $plan = $this->service()->plan([[
            'group_id' => 5,
            'fingerprint' => 'x',
            'source' => 'php',
            'severity' => 'error',
            'error_type' => 'Exception',
            'message' => 'Broken',
            'file' => 'var/view_preprocessed/pub/static/vendor/magento/module-review/view/frontend/templates/form.phtml',
            'line' => 1,
            'status' => 0,
            'occurrence_count' => 1,
            'first_seen_at' => '2026-07-01 00:00:00',
            'last_seen_at' => '2026-07-01 00:00:00',
            'emailed_count' => 0,
            'last_emailed_date' => null,
        ]]);
        $this->assertSame(
            'vendor/magento/module-review/view/frontend/templates/form.phtml',
            $plan['updates'][5]['file']
        );
    }

    public function testDryRunWritesNothing(): void
    {
        $this->rows = $this->sampleGroups();
        $stats = $this->service()->run(true);
        $this->assertTrue($stats['dry_run']);
        $this->assertSame(1, $stats['merged_groups']);
        $this->assertSame(2, $stats['deleted_groups']);
        $this->assertSame([], $this->calls);
    }

    public function testRunMovesEventsInsideOneTransaction(): void
    {
        $this->rows = $this->sampleGroups();
        $stats = $this->service()->run(false);

        $this->assertSame(['beginTransaction'], $this->calls[0]);
        $this->assertSame(['commit'], end($this->calls));
        $this->assertSame(
            ['update', 'panth_error_event', ['group_id' => 251], ['group_id IN (?)' => [1338, 1286]]],
            $this->calls[1]
        );
        $this->assertSame(['delete', 'panth_error_group', ['group_id IN (?)' => [1338, 1286]]], $this->calls[2]);
        $this->assertSame(7, $stats['events_moved']);
        $this->assertSame(hash('sha256', 'refingerprint-pending-251'), $this->calls[3][2]['fingerprint']);
        $this->assertSame(160, $this->calls[4][2]['occurrence_count']);
    }

    public function testFailureRollsBack(): void
    {
        $this->rows = $this->sampleGroups();
        $this->failOnDelete = new \RuntimeException('lock wait timeout');
        try {
            $this->service()->run(false);
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('lock wait timeout', $e->getMessage());
        }
        $this->assertContains(['rollBack'], $this->calls);
        $this->assertNotContains(['commit'], $this->calls);
    }

    public function testNothingToDoSkipsTransaction(): void
    {
        $this->rows = [$this->sampleGroups()[3]];
        $stats = $this->service()->run(false);
        $this->assertSame(0, $stats['updated_groups']);
        $this->assertSame([], $this->calls);
    }
}
