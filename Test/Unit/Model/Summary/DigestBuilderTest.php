<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Model\Summary;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use Panth\ErrorMonitor\Service\BotDetector;
use Panth\ErrorMonitor\Service\NoiseFilter;
use Panth\ErrorMonitor\Service\PathMapper;
use Panth\ErrorMonitor\Service\TimeFormatter;
use PHPUnit\Framework\TestCase;

class DigestBuilderTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

    private array $where = [];

    private array $fetchQueue = [];

    private int $fetches = 0;

    private function builder(): DigestBuilder
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path) => $path === NoiseFilter::XML_ENABLED);
        $scope->method('getValue')->willReturn(null);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->where[] = [$cond, $value];
            return $select;
        });
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('quoteInto')->willReturnCallback(static fn ($text, $value) => str_replace('?', "'" . $value . "'", $text));
        $conn->method('fetchAll')->willReturnCallback(function () {
            $this->fetches++;
            return array_shift($this->fetchQueue) ?? [];
        });

        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($conn);
        $groups->method('getMainTable')->willReturn('panth_error_group');
        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getConnection')->willReturn($conn);
        $events->method('getMainTable')->willReturn('panth_error_event');

        $config = $this->createStub(Config::class);
        $config->method('getEmailMinSeverity')->willReturn('error');
        $config->method('getEmailMaxPerRun')->willReturn(50);

        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('America/Los_Angeles');

        return new DigestBuilder(
            $groups,
            $events,
            $config,
            $scope,
            new BotDetector($scope),
            new NoiseFilter($scope),
            new TimeFormatter($timezone),
            new PathMapper()
        );
    }

    private function group(int $id, array $overrides = []): array
    {
        return $overrides + [
            'group_id' => $id,
            'source' => 'js',
            'severity' => 'error',
            'error_type' => 'TypeError',
            'message' => 'Real problem ' . $id,
            'file' => 'https://shop.test/static/frontend/V/t/en_US/js/app.js',
            'line' => 10,
            'status' => ErrorGroup::STATUS_NEW,
            'occurrence_count' => 100,
            'first_seen_at' => '2026-07-01 12:00:00',
            'last_seen_at' => '2026-10-28 20:34:40',
        ];
    }

    private function options(array $overrides = []): array
    {
        return $overrides + [
            'timezone' => 'America/Los_Angeles',
            'min_severity' => 'error',
            'max_rows' => 50,
            'exclude_bot_only' => true,
            'event_cap' => 50,
            'since' => '2026-10-28 03:00:00',
        ];
    }

    public function testOnlyGroupsWithEventsInWindowAreListedWithCorrectCounts(): void
    {
        $digest = $this->builder()->assemble(
            [
                ['group_id' => 4, 'cnt' => 6, 'last_at' => '2026-10-28 20:34:40'],
                ['group_id' => 12, 'cnt' => 50, 'last_at' => '2026-10-28 10:00:00'],
            ],
            [
                ['group_id' => 4, 'user_agent' => 'Sogou web spider/4.0', 'flagged' => 0, 'cnt' => 3],
                ['group_id' => 4, 'user_agent' => self::CHROME, 'flagged' => 0, 'cnt' => 3],
                ['group_id' => 12, 'user_agent' => null, 'flagged' => 0, 'cnt' => 50],
            ],
            [
                ['group_id' => 4, 'top_url' => 'https://shop.test/b', 'cnt' => 2],
                ['group_id' => 4, 'top_url' => 'https://shop.test/a', 'cnt' => 4],
            ],
            [
                $this->group(4, ['occurrence_count' => 1702]),
                $this->group(5, ['occurrence_count' => 1162]),
                $this->group(12, ['source' => 'php', 'occurrence_count' => 40]),
            ],
            $this->options()
        );

        $this->assertSame([12, 4], array_column($digest['rows'], 'group_id'));
        $byId = array_column($digest['rows'], null, 'group_id');
        $this->assertArrayNotHasKey(5, $byId);

        $this->assertSame(6, $byId[4]['count_24h']);
        $this->assertSame('6', $byId[4]['count_label']);
        $this->assertSame(1702, $byId[4]['occurrence_count']);
        $this->assertSame(3, $byId[4]['bot_count_24h']);
        $this->assertSame(50, $byId[4]['bot_pct']);
        $this->assertSame('https://shop.test/a', $byId[4]['top_url']);
        $this->assertSame('2026-07-01 05:00:00 PDT', $byId[4]['first_seen']);
        $this->assertSame('2026-10-28 13:34:40 PDT', $byId[4]['last_seen']);

        $this->assertSame('50', $byId[12]['count_label']);
        $this->assertSame(50, $byId[12]['occurrence_count']);
        $this->assertSame(0, $byId[12]['bot_pct']);
        $this->assertSame(0, $digest['hidden_total']);
        $this->assertSame('America/Los_Angeles', $digest['timezone']);
    }

    public function testHiddenGroupsAreCountedForTheFooter(): void
    {
        $digest = $this->builder()->assemble(
            [
                ['group_id' => 33, 'cnt' => 115, 'last_at' => '2026-10-28 11:00:00'],
                ['group_id' => 29577, 'cnt' => 3, 'last_at' => '2026-10-28 11:00:00'],
                ['group_id' => 8, 'cnt' => 2, 'last_at' => '2026-10-28 11:00:00'],
                ['group_id' => 1484, 'cnt' => 9, 'last_at' => '2026-10-28 11:00:00'],
                ['group_id' => 9, 'cnt' => 4, 'last_at' => '2026-10-28 11:00:00'],
                ['group_id' => 10, 'cnt' => 4, 'last_at' => '2026-10-28 11:00:00'],
                ['group_id' => 11, 'cnt' => 2, 'last_at' => '2026-10-28 11:00:00'],
            ],
            [
                ['group_id' => 33, 'user_agent' => 'Mozilla/5.0 (compatible; bingbot/2.0)', 'flagged' => 0, 'cnt' => 115],
                ['group_id' => 11, 'user_agent' => self::CHROME, 'flagged' => 1, 'cnt' => 2],
            ],
            [],
            [
                $this->group(33),
                $this->group(29577, ['message' => 'Object Not Found Matching Id:2, MethodName:update, ParamCount:4']),
                $this->group(8, ['status' => ErrorGroup::STATUS_IGNORED]),
                $this->group(1484, [
                    'source' => 'php',
                    'severity' => 'notice',
                    'error_type' => 'GraphQL\Error\Error',
                ]),
                $this->group(9, ['status' => ErrorGroup::STATUS_RESOLVED]),
                $this->group(10, ['severity' => 'warning']),
                $this->group(11),
            ],
            $this->options()
        );

        $this->assertSame([], $digest['rows']);
        $this->assertSame(2, $digest['hidden_ignored']);
        $this->assertSame(2, $digest['hidden_bot']);
        $this->assertSame(1, $digest['hidden_graphql']);
        $this->assertSame(5, $digest['hidden_total']);
    }

    public function testBotOnlyGroupIsListedWhenExclusionIsOff(): void
    {
        $digest = $this->builder()->assemble(
            [['group_id' => 33, 'cnt' => 4, 'last_at' => '2026-10-28 11:00:00']],
            [['group_id' => 33, 'user_agent' => 'DotBot/1.2', 'flagged' => 0, 'cnt' => 4]],
            [],
            [$this->group(33)],
            $this->options(['exclude_bot_only' => false])
        );
        $this->assertSame(100, $digest['rows'][0]['bot_pct']);
    }

    public function testSortingAndLimit(): void
    {
        $digest = $this->builder()->assemble(
            [
                ['group_id' => 1, 'cnt' => 6, 'last_at' => '2026-10-28 11:00:00'],
                ['group_id' => 2, 'cnt' => 6, 'last_at' => '2026-10-28 11:00:00'],
                ['group_id' => 3, 'cnt' => 20, 'last_at' => '2026-10-28 11:00:00'],
                ['group_id' => 4, 'cnt' => 1, 'last_at' => '2026-10-28 11:00:00'],
                ['group_id' => 5, 'cnt' => 0, 'last_at' => '2026-10-28 11:00:00'],
            ],
            [],
            [],
            [
                $this->group(1),
                $this->group(2, ['severity' => 'critical']),
                $this->group(3),
                $this->group(4),
                $this->group(5),
            ],
            $this->options(['max_rows' => 3])
        );
        $this->assertSame([3, 2, 1], array_column($digest['rows'], 'group_id'));
        $this->assertSame(4, $digest['total_groups']);
        $this->assertSame(1, $digest['not_shown']);
    }

    public function testLineReliabilityFlag(): void
    {
        $digest = $this->builder()->assemble(
            [['group_id' => 5, 'cnt' => 2, 'last_at' => '2026-10-28 11:00:00']],
            [],
            [],
            [$this->group(5, [
                'source' => 'php',
                'file' => 'vendor/magento/module-review/view/frontend/templates/form.phtml',
                'line' => 1,
            ])],
            $this->options()
        );
        $this->assertTrue($digest['rows'][0]['line_unreliable']);
    }

    public function testBuildQueriesTheEventWindowInUtc(): void
    {
        $now = gmmktime(23, 0, 0, 10, 28, 2026);
        $this->fetchQueue = [
            [['group_id' => 4, 'cnt' => 2, 'last_at' => '2026-10-28 22:00:00']],
            [['group_id' => 4, 'user_agent' => self::CHROME, 'flagged' => 0, 'cnt' => 2]],
            [['group_id' => 4, 'top_url' => 'https://shop.test/', 'cnt' => 2]],
            [$this->group(4, ['occurrence_count' => 9])],
        ];
        $digest = $this->builder()->build($now);

        $this->assertContains(['created_at >= ?', '2026-10-27 23:00:00'], $this->where);
        $this->assertSame(4, $this->fetches);
        $this->assertSame('2026-10-27 23:00:00', $digest['since_utc']);
        $this->assertSame('2026-10-27 16:00:00 PDT', $digest['since']);
        $this->assertSame(2, $digest['rows'][0]['count_24h']);
        $this->assertSame([4], $this->builder()->groupIds($digest));
    }

    public function testBuildWithoutEventsSkipsFurtherQueries(): void
    {
        $digest = $this->builder()->build(gmmktime(23, 0, 0, 10, 28, 2026));
        $this->assertSame(1, $this->fetches);
        $this->assertSame([], $digest['rows']);
    }
}
