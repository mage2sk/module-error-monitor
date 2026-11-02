<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\DeepTest;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Escaper;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template\Context;
use Panth\ErrorMonitor\Block\Email\Summary;
use Panth\ErrorMonitor\Cron\DispatchNotifications;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\EmailNotifier;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use Panth\ErrorMonitor\Model\Summary\DigestHolder;
use Panth\ErrorMonitor\Service\BotDetector;
use Panth\ErrorMonitor\Service\NoiseFilter;
use Panth\ErrorMonitor\Service\PathMapper;
use Panth\ErrorMonitor\Service\TimeFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep test chunk 3: daily summary query window, timezone handling and email content
 * (owner items #1, #2 and #10).
 */
class SummaryDeepTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';
    private const BOT = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';

    /** 2026-10-29 07:30:00 UTC */
    private const NOW = 1793259000;

    private array $where = [];

    private array $fetchQueue = [];

    private string $iniTimezone = '';

    private ?string $storeZone = 'America/Chicago';

    private array $zoneScopeCodes = [];

    private ?string $countersSince = null;

    protected function setUp(): void
    {
        $this->iniTimezone = (string)ini_get('date.timezone');
    }

    protected function tearDown(): void
    {
        ini_set('date.timezone', $this->iniTimezone !== '' ? $this->iniTimezone : 'UTC');
        date_default_timezone_set($this->iniTimezone !== '' ? $this->iniTimezone : 'UTC');
        (new DigestHolder())->clear();
    }

    private function scope(array $values = []): ScopeConfigInterface
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(
            static fn ($path) => $path === NoiseFilter::XML_ENABLED || !empty($values[$path])
        );
        $scope->method('getValue')->willReturnCallback(static fn ($path) => $values[$path] ?? null);
        return $scope;
    }

    private function timeFormatter(): TimeFormatter
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturnCallback(function ($scopeType = null, $scopeCode = null) {
            $this->zoneScopeCodes[] = $scopeCode;
            if ($scopeCode !== null && (string)$scopeCode === '2') {
                return 'Asia/Kolkata';
            }
            return $this->storeZone;
        });
        return new TimeFormatter($timezone);
    }

    private function builder(array $scopeValues = []): DigestBuilder
    {
        $scope = $this->scope($scopeValues);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->where[] = [$cond, $value];
            return $select;
        });
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('quoteInto')->willReturnCallback(
            static fn ($text, $value) => str_replace('?', "'" . $value . "'", $text)
        );
        $conn->method('fetchAll')->willReturnCallback(fn () => array_shift($this->fetchQueue) ?? []);
        $conn->method('fetchOne')->willReturnCallback(fn () => $this->countersSince);

        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($conn);
        $groups->method('getMainTable')->willReturn('panth_error_group');
        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getConnection')->willReturn($conn);
        $events->method('getMainTable')->willReturn('panth_error_event');

        $config = $this->createStub(Config::class);
        $config->method('getEmailMinSeverity')->willReturn('error');
        $config->method('getEmailMaxPerRun')->willReturn(50);

        return new DigestBuilder(
            $groups,
            $events,
            $config,
            $scope,
            new BotDetector($scope),
            new NoiseFilter($scope),
            $this->timeFormatter(),
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
            'last_seen_at' => '2026-10-29 07:00:00',
        ];
    }

    private function options(array $overrides = []): array
    {
        return $overrides + [
            'timezone' => 'America/Chicago',
            'min_severity' => 'error',
            'max_rows' => 50,
            'exclude_bot_only' => true,
            'event_cap' => 50,
            'since' => '2026-10-28 07:30:00',
        ];
    }

    private function sinceFromBuild(): string
    {
        foreach ($this->where as [$cond, $value]) {
            if ($cond === 'created_at >= ?') {
                return (string)$value;
            }
        }
        $this->fail('created_at window condition not found');
    }

    // ------------------------------------------------------------------ window boundary (#1, #2)

    public static function phpZones(): array
    {
        return [
            'UTC' => ['UTC'],
            'Chicago' => ['America/Chicago'],
            'Kolkata' => ['Asia/Kolkata'],
            'Kiritimati +14' => ['Pacific/Kiritimati'],
            'Pago Pago -11' => ['Pacific/Pago_Pago'],
        ];
    }

    /**
     * @dataProvider phpZones
     */
    #[DataProvider('phpZones')]
    public function testWindowStartIsUtcNowMinus24hWhateverThePhpTimezone(string $zone): void
    {
        ini_set('date.timezone', $zone);
        date_default_timezone_set($zone);
        $this->fetchQueue = [[]];

        $digest = $this->builder()->build(self::NOW);

        $this->assertSame('2026-10-28 07:30:00', $this->sinceFromBuild());
        $this->assertSame('2026-10-28 07:30:00', $digest['since_utc']);
        $this->assertSame('2026-10-28 02:30:00 CDT', $digest['since']);
    }

    /**
     * The SQL uses created_at >= since (inclusive). Rows are written with gmdate() and the Magento PDO
     * connection forces time_zone '+00:00', so a plain string comparison is what MySQL does.
     * @dataProvider phpZones
     */
    #[DataProvider('phpZones')]
    public function testBoundaryEventsAtExactlyMinusOneAndPlusOneSecond(string $zone): void
    {
        ini_set('date.timezone', $zone);
        date_default_timezone_set($zone);
        $this->fetchQueue = [[]];
        $this->builder()->build(self::NOW);
        $since = $this->sinceFromBuild();

        $at = static fn (int $offset): string => gmdate('Y-m-d H:i:s', self::NOW - 86400 + $offset);
        $this->assertTrue($at(0) >= $since, 'event at exactly now-24h must be inside the window');
        $this->assertFalse($at(-1) >= $since, 'event at now-24h-1s must be outside the window');
        $this->assertTrue($at(1) >= $since, 'event at now-24h+1s must be inside the window');
    }

    public function testAllWindowQueriesUseTheSameSinceValue(): void
    {
        $this->fetchQueue = [
            [['group_id' => 4, 'cnt' => 2, 'first_at' => '2026-10-28 08:00:00', 'last_at' => '2026-10-29 07:00:00']],
            [['group_id' => 4, 'user_agent' => self::CHROME, 'flagged' => 0, 'cnt' => 2]],
            [['group_id' => 4, 'top_url' => 'https://shop.test/a', 'cnt' => 2]],
            [$this->group(4)],
        ];
        $this->builder()->build(self::NOW);
        $sinces = array_values(array_unique(array_map(
            static fn ($w) => $w[1],
            array_filter($this->where, static fn ($w) => $w[0] === 'created_at >= ?')
        )));
        $this->assertSame(['2026-10-28 07:30:00'], $sinces);
    }

    // ------------------------------------------------------------------ DST labels (#2)

    public static function dstCases(): array
    {
        return [
            'fall back: last CDT second' => ['2026-11-01 06:59:59', '2026-11-01 01:59:59 CDT'],
            'fall back: first CST second (repeated 01:00)' => ['2026-11-01 07:00:00', '2026-11-01 01:00:00 CST'],
            'spring forward: last CST second' => ['2026-03-08 07:59:59', '2026-03-08 01:59:59 CST'],
            'spring forward: first CDT second (02:xx skipped)' => ['2026-03-08 08:00:00', '2026-03-08 03:00:00 CDT'],
            'summer' => ['2026-07-04 17:00:00', '2026-07-04 12:00:00 CDT'],
            'winter' => ['2026-01-15 18:00:00', '2026-01-15 12:00:00 CST'],
        ];
    }

    /**
     * @dataProvider dstCases
     */
    #[DataProvider('dstCases')]
    public function testStoreTimeLabelIsCorrectPerTimestampAcrossDst(string $utc, string $expected): void
    {
        foreach (['UTC', 'Asia/Kolkata', 'Pacific/Kiritimati'] as $phpZone) {
            date_default_timezone_set($phpZone);
            $this->assertSame($expected, $this->timeFormatter()->format($utc, 'America/Chicago'), $phpZone);
        }
    }

    public function testWindowOnThe25HourDayIsStill24RealHours(): void
    {
        // 2026-11-02 05:00 UTC = 2026-11-01 23:00 CST, the day clocks fell back.
        $now = gmmktime(5, 0, 0, 11, 2, 2026);
        $this->fetchQueue = [[]];
        $digest = $this->builder()->build($now);
        $this->assertSame('2026-11-01 05:00:00', $digest['since_utc']);
        $this->assertSame('2026-11-01 00:00:00 CDT', $digest['since']);
    }

    public function testWindowOnThe23HourDayIsStill24RealHours(): void
    {
        // 2026-03-09 04:00 UTC = 2026-03-08 23:00 CDT, the day clocks sprang forward.
        $now = gmmktime(4, 0, 0, 3, 9, 2026);
        $this->fetchQueue = [[]];
        $digest = $this->builder()->build($now);
        $this->assertSame('2026-03-08 04:00:00', $digest['since_utc']);
        $this->assertSame('2026-03-07 22:00:00 CST', $digest['since']);
    }

    public function testRowFirstAndLastSeenUseTheirOwnDstOffset(): void
    {
        $digest = $this->builder()->assemble(
            [['group_id' => 1, 'cnt' => 3, 'last_at' => '2026-11-01 07:30:00']],
            [],
            [],
            [$this->group(1, ['first_seen_at' => '2026-07-01 12:00:00', 'last_seen_at' => '2026-11-01 06:30:00'])],
            $this->options()
        );
        $row = $digest['rows'][0];
        $this->assertSame('2026-07-01 07:00:00 CDT', $row['first_seen']);
        // last_at from the event table is newer than the group's last_seen_at, so it wins.
        $this->assertSame('2026-11-01 01:30:00 CST', $row['last_seen']);
    }

    public function testInvalidOrEmptyStoreTimezoneFallsBackToUtc(): void
    {
        $this->storeZone = 'Mars/Olympus';
        $this->assertSame('UTC', $this->timeFormatter()->storeTimezone());
        $this->storeZone = null;
        $this->assertSame('UTC', $this->timeFormatter()->storeTimezone());
        $this->assertSame('', $this->timeFormatter()->format('0000-00-00 00:00:00', 'UTC'));
        $this->assertSame('', $this->timeFormatter()->format(null, 'UTC'));
    }

    public function testDigestUsesTheCurrentStoreScopeTimezoneNotPerGroupStore(): void
    {
        $this->fetchQueue = [[]];
        $this->builder()->build(self::NOW);
        // Documented limitation: one timezone (current store scope, null scope code) for the whole email,
        // even when a group's events came from a store view with another timezone.
        $this->assertSame([null], array_values(array_unique($this->zoneScopeCodes, SORT_REGULAR)));
        $this->assertSame('Asia/Kolkata', $this->timeFormatter()->storeTimezone(2));
    }

    // ------------------------------------------------------------------ selection (#1, #3, #6, #7)

    public function testGroupWithoutEventsInWindowIsExcludedEvenIfLastSeenIsRecent(): void
    {
        $digest = $this->builder()->assemble(
            [['group_id' => 2, 'cnt' => 0, 'last_at' => null]],
            [],
            [],
            [
                $this->group(1, ['last_seen_at' => '2026-10-29 07:29:59']),
                $this->group(2, ['last_seen_at' => '2026-10-29 07:29:59']),
            ],
            $this->options()
        );
        $this->assertSame([], $digest['rows']);
        $this->assertSame(0, $digest['hidden_total']);
    }

    public function testBotOnlyGroupIsHiddenAndCountedInFooter(): void
    {
        $digest = $this->builder()->assemble(
            [
                ['group_id' => 33, 'cnt' => 115, 'last_at' => '2026-10-29 07:00:00'],
                ['group_id' => 34, 'cnt' => 4, 'last_at' => '2026-10-29 07:00:00'],
            ],
            [
                ['group_id' => 33, 'user_agent' => self::BOT, 'flagged' => 0, 'cnt' => 100],
                ['group_id' => 33, 'user_agent' => self::CHROME, 'flagged' => 1, 'cnt' => 15],
                ['group_id' => 34, 'user_agent' => self::CHROME, 'flagged' => 0, 'cnt' => 4],
            ],
            [],
            [$this->group(33), $this->group(34)],
            $this->options()
        );
        $this->assertSame([34], array_column($digest['rows'], 'group_id'));
        $this->assertSame(1, $digest['hidden_bot']);
        $this->assertSame(1, $digest['hidden_total']);
    }

    public function testBotOnlyGroupIsListedWhenExclusionIsOff(): void
    {
        $digest = $this->builder()->assemble(
            [['group_id' => 33, 'cnt' => 5, 'last_at' => '2026-10-29 07:00:00']],
            [['group_id' => 33, 'user_agent' => self::BOT, 'flagged' => 0, 'cnt' => 5]],
            [],
            [$this->group(33)],
            $this->options(['exclude_bot_only' => false])
        );
        $this->assertSame(100, $digest['rows'][0]['bot_pct']);
        $this->assertSame(0, $digest['hidden_bot']);
    }

    public static function botMixes(): array
    {
        return [
            '1 of 3' => [3, 1, 33],
            '2 of 3' => [3, 2, 67],
            'half' => [2, 1, 50],
            '1 of 200 rounds half up' => [200, 1, 1],
            '1 of 201 shows 0 although bots exist' => [201, 1, 0],
            '199 of 200 shows 100 although humans exist' => [200, 199, 100],
            'none' => [10, 0, 0],
        ];
    }

    /**
     * @dataProvider botMixes
     */
    #[DataProvider('botMixes')]
    public function testBotPercentageRounding(int $count, int $bots, int $expectedPct): void
    {
        $uaRows = [['group_id' => 1, 'user_agent' => self::CHROME, 'flagged' => 0, 'cnt' => $count - $bots]];
        if ($bots > 0) {
            $uaRows[] = ['group_id' => 1, 'user_agent' => self::BOT, 'flagged' => 0, 'cnt' => $bots];
        }
        $digest = $this->builder()->assemble(
            [['group_id' => 1, 'cnt' => $count, 'last_at' => '2026-10-29 07:00:00']],
            $uaRows,
            [],
            [$this->group(1)],
            $this->options()
        );
        $this->assertSame($expectedPct, $digest['rows'][0]['bot_pct']);
        $this->assertSame($bots, $digest['rows'][0]['bot_count_24h']);
    }

    public function testIgnoredStatusIgnoreRuleAndGraphqlNoticeAreCountedSeparately(): void
    {
        $digest = $this->builder([
            'panth_errormonitor/general/ignore_patterns' => "ResizeObserver loop",
        ])->assemble(
            [
                ['group_id' => 1, 'cnt' => 1, 'last_at' => '2026-10-29 07:00:00'],
                ['group_id' => 2, 'cnt' => 1, 'last_at' => '2026-10-29 07:00:00'],
                ['group_id' => 3, 'cnt' => 1, 'last_at' => '2026-10-29 07:00:00'],
                ['group_id' => 4, 'cnt' => 1, 'last_at' => '2026-10-29 07:00:00'],
                ['group_id' => 5, 'cnt' => 1, 'last_at' => '2026-10-29 07:00:00'],
                ['group_id' => 6, 'cnt' => 1, 'last_at' => '2026-10-29 07:00:00'],
            ],
            [],
            [],
            [
                $this->group(1, ['status' => ErrorGroup::STATUS_IGNORED]),
                $this->group(2, ['message' => 'ResizeObserver loop limit exceeded']),
                $this->group(3, ['message' => 'Object Not Found Matching Id:2, MethodName:update, ParamCount:4']),
                $this->group(4, ['severity' => 'notice', 'error_type' => 'GraphQL\\Error\\Error']),
                $this->group(5, ['status' => ErrorGroup::STATUS_RESOLVED]),
                $this->group(6, ['severity' => 'warning']),
            ],
            $this->options()
        );
        $this->assertSame([], $digest['rows']);
        $this->assertSame(3, $digest['hidden_ignored']);
        $this->assertSame(1, $digest['hidden_graphql']);
        // Resolved groups and groups below the email severity are dropped silently (not in the footer).
        $this->assertSame(4, $digest['hidden_total']);
    }

    // ------------------------------------------------------------------ sorting

    public function testSortIsBy24hCountThenSeverityThenLastSeenThenGroupId(): void
    {
        $counts = [
            ['group_id' => 10, 'cnt' => 5, 'last_at' => '2026-10-29 01:00:00'],
            ['group_id' => 11, 'cnt' => 9, 'last_at' => '2026-10-29 01:00:00'],
            ['group_id' => 12, 'cnt' => 5, 'last_at' => '2026-10-29 01:00:00'],
            ['group_id' => 13, 'cnt' => 5, 'last_at' => '2026-10-29 02:00:00'],
            ['group_id' => 14, 'cnt' => 5, 'last_at' => '2026-10-29 01:00:00'],
            ['group_id' => 15, 'cnt' => 1, 'last_at' => '2026-10-29 06:00:00'],
        ];
        $groups = [
            $this->group(10, ['last_seen_at' => '2026-10-29 01:00:00']),
            $this->group(11, ['last_seen_at' => '2026-10-29 01:00:00']),
            $this->group(12, ['last_seen_at' => '2026-10-29 01:00:00', 'severity' => 'critical']),
            $this->group(13, ['last_seen_at' => '2026-10-29 02:00:00']),
            $this->group(14, ['last_seen_at' => '2026-10-29 01:00:00']),
            $this->group(15, ['last_seen_at' => '2026-10-29 06:00:00']),
        ];
        $expected = [11, 12, 13, 10, 14, 15];
        for ($i = 0; $i < 5; $i++) {
            shuffle($counts);
            shuffle($groups);
            $digest = $this->builder()->assemble($counts, [], [], $groups, $this->options());
            $this->assertSame($expected, array_column($digest['rows'], 'group_id'));
        }
    }

    public function testPerEmailLimitKeepsTopRowsAndReportsTheRest(): void
    {
        $counts = [];
        $groups = [];
        for ($id = 1; $id <= 7; $id++) {
            $counts[] = ['group_id' => $id, 'cnt' => $id, 'last_at' => '2026-10-29 07:00:00'];
            $groups[] = $this->group($id);
        }
        $digest = $this->builder()->assemble($counts, [], [], $groups, $this->options(['max_rows' => 3]));
        $this->assertSame([7, 6, 5], array_column($digest['rows'], 'group_id'));
        $this->assertSame(7, $digest['total_groups']);
        $this->assertSame(4, $digest['not_shown']);
    }

    // ------------------------------------------------------------------ event cap

    public static function capLabels(): array
    {
        return [
            'below cap' => [49, 50, '49'],
            'exactly at cap' => [50, 50, '50+'],
            'cap disabled' => [50, 0, '50'],
            'cap lowered after rows were stored' => [80, 50, '80+'],
        ];
    }

    /**
     * @dataProvider capLabels
     */
    #[DataProvider('capLabels')]
    public function testCapLabel(int $count, int $cap, string $label): void
    {
        $digest = $this->builder()->assemble(
            [['group_id' => 4, 'cnt' => $count, 'last_at' => '2026-10-29 07:00:00']],
            [],
            [],
            [$this->group(4, ['occurrence_count' => 1702])],
            $this->options(['event_cap' => $cap])
        );
        $this->assertSame($label, $digest['rows'][0]['count_label']);
        $block = $this->summaryBlock(['rows' => $digest['rows']]);
        $this->assertSame($label . ' in last 24 h (1702 total)', $block->formatCounts($digest['rows'][0]));
    }

    public function testCapDefaultComesFromConfigWhenUnset(): void
    {
        $this->fetchQueue = [
            [['group_id' => 4, 'cnt' => 50, 'last_at' => '2026-10-29 07:00:00']],
            [],
            [],
            [$this->group(4)],
        ];
        $digest = $this->builder()->build(self::NOW);
        $this->assertSame('50+', $digest['rows'][0]['count_label']);
    }

    /**
     * FAILS NOW: etc/config.xml ships event_cap_mode = newest, so every install (including upgrades from
     * 1.6.x with no stored value) starts deleting the oldest occurrence row - with its stack trace and
     * context - once a group has 50 rows. The owner did not ask for a default behaviour change.
     */
    public function testEventCapDefaultDoesNotDeleteStoredOccurrences(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');
        $mode = (string)$xml->default->panth_errormonitor->general->event_cap_mode;
        $this->assertSame('oldest', $mode, 'Default cap mode deletes stored occurrence rows (data loss by default)');
    }

    /**
     * In "keep the oldest rows" mode a group at the cap gets no new event rows, so the window count comes
     * from the exact hourly counters, which are written for every occurrence (capped and throttled too).
     */
    public function testCappedGroupInKeepOldestModeStillAppearsWhenItFiredToday(): void
    {
        $this->countersSince = '2026-10-01 00:00:00';
        $this->fetchQueue = [
            [['group_id' => 4, 'cnt' => 1000, 'bot_cnt' => 0]],
            [],
            [],
            [$this->group(4, ['occurrence_count' => 5000, 'last_seen_at' => '2026-10-29 07:20:00'])],
        ];
        $digest = $this->builder([
            'panth_errormonitor/general/event_cap_mode' => 'oldest',
        ])->build(self::NOW);
        $this->assertSame([4], array_column($digest['rows'], 'group_id'));
        $this->assertSame(1000, $digest['rows'][0]['count_24h']);
        $this->assertSame('1000', $digest['rows'][0]['count_label'], 'exact counters never show "+"');
        $this->assertSame(['hour_start >= ?', '2026-10-28 08:00:00'], $this->where[0]);
        $this->assertContains(['created_at >= ?', '2026-10-28 07:30:00'], $this->where);
        $this->assertContains(['created_at < ?', '2026-10-28 08:00:00'], $this->where);
    }

    // ------------------------------------------------------------------ top URL

    public function testTopUrlTieBreaksAlphabeticallyAndSkipsNullOrEmpty(): void
    {
        $digest = $this->builder()->assemble(
            [
                ['group_id' => 1, 'cnt' => 6, 'last_at' => '2026-10-29 07:00:00'],
                ['group_id' => 2, 'cnt' => 2, 'last_at' => '2026-10-29 07:00:00'],
            ],
            [],
            [
                ['group_id' => 1, 'top_url' => 'https://shop.test/z', 'cnt' => 2],
                ['group_id' => 1, 'top_url' => 'https://shop.test/b', 'cnt' => 2],
                ['group_id' => 1, 'top_url' => null, 'cnt' => 5],
                ['group_id' => 1, 'top_url' => '', 'cnt' => 5],
                ['group_id' => 2, 'top_url' => null, 'cnt' => 2],
            ],
            [$this->group(1), $this->group(2)],
            $this->options()
        );
        $rows = array_column($digest['rows'], null, 'group_id');
        $this->assertSame('https://shop.test/b', $rows[1]['top_url']);
        $this->assertSame('', $rows[2]['top_url']);
    }

    // ------------------------------------------------------------------ template rendering (#10)

    private function summaryBlock(array $digest, array &$urlParams = []): Summary
    {
        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturnCallback(function ($route, $params) use (&$urlParams) {
            $urlParams = $params;
            return 'https://hyva.test/admin/' . $route . '/group_id/' . $params['group_id'] . '/key/abc/';
        });
        (new DigestHolder())->set($digest);
        return new Summary(
            $this->createStub(Context::class),
            $this->createStub(CollectionFactory::class),
            $url,
            [],
            new DigestHolder(),
            $this->createStub(DigestBuilder::class)
        );
    }

    private function render(Summary $block): string
    {
        $escaper = new Escaper();
        $prop = new \ReflectionProperty(Escaper::class, 'escaper');
        $prop->setValue($escaper, new \Laminas\Escaper\Escaper());
        $inline = $this->createStub(\Magento\Framework\Translate\InlineInterface::class);
        $inline->method('isAllowed')->willReturn(false);
        (new \ReflectionProperty(Escaper::class, 'translateInline'))->setValue($escaper, $inline);
        $file = dirname(__DIR__, 3) . '/view/frontend/templates/email/summary.phtml';
        ob_start();
        try {
            (static function () use ($block, $escaper, $file) {
                include $file;
            })();
        } finally {
            $html = (string)ob_get_clean();
        }
        return $html;
    }

    private function row(array $overrides = []): array
    {
        return $overrides + [
            'group_id' => 4,
            'severity' => 'error',
            'source' => 'js',
            'error_type' => 'TypeError',
            'message' => 'msg',
            'file' => 'app.js',
            'line' => 3,
            'line_unreliable' => false,
            'top_url' => 'https://shop.test/',
            'count_24h' => 6,
            'count_label' => '6',
            'bot_count_24h' => 0,
            'bot_pct' => 0,
            'occurrence_count' => 1702,
            'first_seen' => '2026-07-01 07:00:00 CDT',
            'last_seen' => '2026-10-28 15:34:40 CDT',
        ];
    }

    public function testTemplateEscapesMessageUrlFileTypeAndSource(): void
    {
        $html = $this->render($this->summaryBlock([
            'rows' => [$this->row([
                'source' => '<b>js</b>',
                'error_type' => '<svg onload=alert(1)>',
                'message' => '<script>alert(document.cookie)</script>',
                'file' => '"><img src=x onerror=alert(2)>.js',
                'top_url' => 'https://shop.test/?q="><iframe src=javascript:alert(3)>',
                'first_seen' => '<i>x</i>',
            ])],
            'timezone' => 'America/Chicago',
            'hidden_total' => 0,
        ]));
        foreach (['<script', '<svg', '<img', '<iframe', '<b>js', '<i>x'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $html, $needle);
        }
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('6 in last 24 h (1702 total)', $html);
        $this->assertStringContainsString('Bots: 0%', preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    public function testLongMessageAndUrlAreTruncated(): void
    {
        $html = $this->render($this->summaryBlock([
            'rows' => [$this->row([
                'message' => str_repeat('ä', 5000),
                'top_url' => 'https://shop.test/' . str_repeat('p', 3000),
            ])],
            'timezone' => 'UTC',
        ]));
        $this->assertStringContainsString(str_repeat('ä', 300) . '...', $html);
        $this->assertStringNotContainsString(str_repeat('ä', 301), $html);
        $this->assertStringNotContainsString(str_repeat('p', 300), $html);
    }

    public function testNonUtf8MessageStillRendersValidUtf8(): void
    {
        $html = $this->render($this->summaryBlock([
            'rows' => [$this->row(['message' => "Bad bytes \xC3\x28 and \xFF end", 'file' => "f\xFE.js"])],
            'timezone' => 'UTC',
        ]));
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
        $this->assertStringContainsString('Bad bytes', $html);
        $this->assertStringContainsString('end', $html);
    }

    public function testEmptyDigestRendersNoErrorsLine(): void
    {
        $html = $this->render($this->summaryBlock(['rows' => [], 'timezone' => 'UTC', 'hidden_total' => 0]));
        $this->assertStringContainsString('No errors to report.', $html);
    }

    /**
     * FAILS NOW: the link is built with a secret key derived from the cron process's own form key.
     * With "Add Secret Key to URLs" on (Magento default) a logged-in admin is redirected to the dashboard
     * ("invalid Secret Key"), and after a fresh login Magento also goes to the startup page, so
     * "View details" never opens the group. Needs '_nosecret' plus a View controller that accepts it
     * (e.g. $_publicActions = ['view'] - still ACL protected).
     */
    public function testAdminLinkIsBuiltWithoutAPerProcessSecretKey(): void
    {
        $params = [];
        $block = $this->summaryBlock(['rows' => [$this->row()]], $params);
        $block->getViewUrl($this->row());
        $this->assertSame(4, $params['group_id']);
        $this->assertArrayHasKey('_nosecret', $params);
    }

    // ------------------------------------------------------------------ empty day

    public function testEmptyDayDoesNotSendAndDoesNotSetTheDailyFlag(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isEmailEnabled')->willReturn(true);
        $config->method('getEmailRecipients')->willReturn(['ops@example.test']);
        $config->method('getEmailSendHour')->willReturn(0);

        $builder = $this->createStub(DigestBuilder::class);
        $builder->method('build')->willReturn(['rows' => [], 'hidden_total' => 3, 'hidden_bot' => 3]);
        $builder->method('groupIds')->willReturn([]);

        $notifier = $this->createMock(EmailNotifier::class);
        $notifier->expects($this->never())->method('sendDigest');
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturn(null);
        $flags->expects($this->never())->method('saveFlag');

        (new DispatchNotifications(
            $config,
            $this->createStub(CollectionFactory::class),
            $this->createStub(ErrorGroupResource::class),
            $notifier,
            $flags,
            $this->createStub(LoggerInterface::class),
            $builder
        ))->execute();
    }
}
