<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Summary;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Sql\Expression;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Service\BotDetector;
use Panth\ErrorMonitor\Service\NoiseFilter;
use Panth\ErrorMonitor\Service\PathMapper;
use Panth\ErrorMonitor\Service\TimeFormatter;

class DigestBuilder
{
    public const WINDOW_HOURS = 24;

    public const XML_EXCLUDE_BOT_ONLY = 'panth_errormonitor/filters/email_exclude_bot_only';

    private const XML_EVENT_CAP = 'panth_errormonitor/general/max_events_per_group';

    private const DEFAULT_EVENT_CAP = 50;

    private const ID_CHUNK = 500;

    private const BOT_FLAG_LIKE = '%"is_bot":true%';

    private const GRAPHQL_TYPE = '/graphql/i';

    private array $zones = [];

    public function __construct(
        private readonly ErrorGroupResource $groupResource,
        private readonly ErrorEventResource $eventResource,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly BotDetector $botDetector,
        private readonly NoiseFilter $noiseFilter,
        private readonly TimeFormatter $timeFormatter,
        private readonly PathMapper $pathMapper
    ) {
    }

    public function build(?int $now = null, ?array $onlyGroupIds = null): array
    {
        $now ??= time();
        $since = $this->timeFormatter->utcHoursAgo(self::WINDOW_HOURS, $now);
        $timezone = $this->timeFormatter->storeTimezone();
        $options = $this->options($timezone, $onlyGroupIds === null) + ['since' => $since];

        if ($onlyGroupIds !== null) {
            $onlyGroupIds = array_values(array_filter(array_map('intval', $onlyGroupIds)));
            if ($onlyGroupIds === []) {
                return $this->assemble([], [], [], [], $options);
            }
        }

        $countersSince = $this->countersSince();
        $hourlyRows = [];
        $eventUntil = null;
        $useEvents = true;
        if ($countersSince !== null) {
            $sinceTs = $now - self::WINDOW_HOURS * 3600;
            $firstFullHour = gmdate('Y-m-d H:i:s', $sinceTs % 3600 === 0 ? $sinceTs : $sinceTs - $sinceTs % 3600 + 3600);
            $eventUntil = max($firstFullHour, $countersSince);
            $useEvents = $eventUntil > $since;
            $hourlyRows = $this->fetchHourlyCounts($eventUntil, $onlyGroupIds);
        }
        $eventRows = $useEvents ? $this->fetchWindowCounts($since, $onlyGroupIds, $eventUntil) : [];

        $countRows = $this->mergeCounts($hourlyRows, $eventRows);
        if ($countRows === []) {
            return $this->assemble([], [], [], [], $options);
        }
        $ids = array_map(static fn (array $row): int => (int)$row['group_id'], $countRows);
        $eventIds = array_map(static fn (array $row): int => (int)$row['group_id'], $eventRows);

        return $this->assemble(
            $countRows,
            $eventIds !== [] ? $this->fetchUserAgentRows($since, $eventIds, $eventUntil) : [],
            $this->fetchUrlRows($since, $ids),
            $this->fetchGroups($ids),
            $options
        );
    }

    public function assemble(
        array $countRows,
        array $userAgentRows,
        array $urlRows,
        array $groupRows,
        array $options
    ): array {
        $timezone = (string)($options['timezone'] ?? 'UTC');
        $minRank = Severity::rank((string)($options['min_severity'] ?? 'error'));
        $maxRows = max(1, (int)($options['max_rows'] ?? 50));
        $excludeBotOnly = (bool)($options['exclude_bot_only'] ?? true);
        $eventCap = (int)($options['event_cap'] ?? 0);
        $applyLimit = (bool)($options['apply_limit'] ?? true);

        $groups = [];
        foreach ($groupRows as $row) {
            $groups[(int)$row['group_id']] = $row;
        }
        $bots = $this->botCounts($userAgentRows);
        $topUrls = $this->topUrls($urlRows);

        $rows = [];
        $zones = [];
        $hidden = ['ignored' => 0, 'bot' => 0, 'graphql' => 0];
        $resolvedActive = 0;
        foreach ($countRows as $countRow) {
            $id = (int)$countRow['group_id'];
            $count = (int)$countRow['cnt'];
            if ($count <= 0 || !isset($groups[$id])) {
                continue;
            }
            $group = $groups[$id];
            $status = (int)($group['status'] ?? ErrorGroup::STATUS_NEW);
            $severity = strtolower((string)($group['severity'] ?? 'error'));

            if ($status === ErrorGroup::STATUS_RESOLVED) {
                $resolvedActive++;
                continue;
            }
            if ($status === ErrorGroup::STATUS_IGNORED || $this->matchesIgnoreRules($group)) {
                $hidden['ignored']++;
                continue;
            }
            if ($severity === 'notice' && preg_match(self::GRAPHQL_TYPE, (string)($group['error_type'] ?? '')) === 1) {
                $hidden['graphql']++;
                continue;
            }
            if (Severity::rank($severity) < $minRank) {
                continue;
            }
            $botCount = min($count, ($bots[$id] ?? 0) + (int)($countRow['bot_cnt'] ?? 0));
            if ($excludeBotOnly && $botCount >= $count) {
                $hidden['bot']++;
                continue;
            }

            $line = isset($group['line']) && $group['line'] !== null ? (int)$group['line'] : null;
            $file = (string)($group['file'] ?? '');
            $total = (int)($group['occurrence_count'] ?? 0);
            $firstSeen = (string)($group['first_seen_at'] ?? '');
            $lastSeen = $this->latest((string)($group['last_seen_at'] ?? ''), (string)($countRow['last_at'] ?? ''));
            $zone = $this->rowTimezone($group, $timezone);
            $zones[$zone] = true;
            $rows[] = [
                'group_id' => $id,
                'severity' => $severity,
                'source' => (string)($group['source'] ?? ''),
                'error_type' => (string)($group['error_type'] ?? ''),
                'message' => (string)($group['message'] ?? ''),
                'file' => $file,
                'line' => $line,
                'line_unreliable' => (int)($group['line_unreliable'] ?? 0) === 1
                    || $this->pathMapper->isLineUnreliable($file, $line),
                'top_url' => $topUrls[$id] ?? '',
                'count_24h' => $count,
                'count_label' => $this->countLabel($count, (int)($countRow['exact_cnt'] ?? 0), $total, $eventCap),
                'bot_count_24h' => $botCount,
                'bot_pct' => (int)round($botCount * 100 / $count),
                'occurrence_count' => max($total, $count),
                'first_seen_utc' => $firstSeen,
                'last_seen_utc' => $lastSeen,
                'timezone' => $zone,
                'first_seen' => $this->timeFormatter->format($firstSeen, $zone),
                'last_seen' => $this->timeFormatter->format($lastSeen, $zone),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$b['count_24h'], Severity::rank($b['severity']), $b['last_seen_utc'], $a['group_id']]
                <=> [$a['count_24h'], Severity::rank($a['severity']), $a['last_seen_utc'], $b['group_id']];
        });

        $total = count($rows);
        if ($applyLimit && $total > $maxRows) {
            $rows = array_slice($rows, 0, $maxRows);
        }

        return [
            'since_utc' => (string)($options['since'] ?? ''),
            'since' => $this->timeFormatter->format((string)($options['since'] ?? ''), $timezone),
            'timezone' => $timezone,
            'mixed_timezones' => count($zones) > 1 || ($zones !== [] && !isset($zones[$timezone])),
            'rows' => $rows,
            'group_count' => count($rows),
            'total_groups' => $total,
            'not_shown' => $total - count($rows),
            'hidden_ignored' => $hidden['ignored'],
            'hidden_bot' => $hidden['bot'],
            'hidden_graphql' => $hidden['graphql'],
            'hidden_total' => array_sum($hidden),
            'resolved_active' => $resolvedActive,
        ];
    }

    public function groupIds(array $digest): array
    {
        return array_values(array_map(static fn (array $row): int => (int)$row['group_id'], $digest['rows'] ?? []));
    }

    public function mergeCounts(array $hourlyRows, array $eventRows): array
    {
        $merged = [];
        foreach ($hourlyRows as $row) {
            $id = (int)$row['group_id'];
            $count = (int)($row['cnt'] ?? 0);
            $merged[$id] = [
                'group_id' => $id,
                'cnt' => $count,
                'exact_cnt' => $count,
                'bot_cnt' => (int)($row['bot_cnt'] ?? 0),
                'last_at' => '',
            ];
        }
        foreach ($eventRows as $row) {
            $id = (int)$row['group_id'];
            $merged[$id] ??= ['group_id' => $id, 'cnt' => 0, 'exact_cnt' => 0, 'bot_cnt' => 0, 'last_at' => ''];
            $merged[$id]['cnt'] += (int)($row['cnt'] ?? 0);
            $merged[$id]['last_at'] = $this->latest($merged[$id]['last_at'], (string)($row['last_at'] ?? ''));
        }
        return array_values($merged);
    }

    private function countLabel(int $count, int $exact, int $total, int $eventCap): string
    {
        $estimated = $count - max(0, $exact);
        if ($eventCap > 0 && $estimated >= $eventCap && $total > $estimated) {
            return $count . '+';
        }
        return (string)$count;
    }

    private function rowTimezone(array $group, string $default): string
    {
        $storeId = (int)($group['store_id'] ?? 0);
        if ($storeId <= 0) {
            return $default;
        }
        if (!isset($this->zones[$storeId])) {
            $this->zones[$storeId] = $this->timeFormatter->storeTimezone($storeId);
        }
        return $this->zones[$storeId];
    }

    private function options(string $timezone, bool $applyLimit): array
    {
        $cap = $this->scopeConfig->getValue(self::XML_EVENT_CAP);
        $exclude = $this->scopeConfig->getValue(self::XML_EXCLUDE_BOT_ONLY);
        return [
            'timezone' => $timezone,
            'min_severity' => $this->config->getEmailMinSeverity(),
            'max_rows' => $this->config->getEmailMaxPerRun(),
            'exclude_bot_only' => $exclude === null || (bool)(int)$exclude,
            'event_cap' => ($cap === null || $cap === '') ? self::DEFAULT_EVENT_CAP : max(0, (int)$cap),
            'apply_limit' => $applyLimit,
        ];
    }

    private function matchesIgnoreRules(array $group): bool
    {
        $hasStack = array_key_exists('has_stack', $group) && $group['has_stack'] !== null
            ? (int)$group['has_stack'] === 1
            : null;
        return $this->noiseFilter->isIgnored(
            (string)($group['message'] ?? ''),
            isset($group['file']) ? (string)$group['file'] : null,
            (string)($group['error_type'] ?? ''),
            null,
            $hasStack
        );
    }

    private function botCounts(array $userAgentRows): array
    {
        $counts = [];
        foreach ($userAgentRows as $row) {
            $id = (int)$row['group_id'];
            $isBot = (int)($row['flagged'] ?? 0) === 1
                || $this->botDetector->isBot(isset($row['user_agent']) ? (string)$row['user_agent'] : null);
            if ($isBot) {
                $counts[$id] = ($counts[$id] ?? 0) + (int)$row['cnt'];
            }
        }
        return $counts;
    }

    private function topUrls(array $urlRows): array
    {
        $best = [];
        foreach ($urlRows as $row) {
            $id = (int)$row['group_id'];
            $url = (string)($row['top_url'] ?? '');
            $count = (int)($row['cnt'] ?? 0);
            if ($url === '') {
                continue;
            }
            if (!isset($best[$id]) || $count > $best[$id][1] || ($count === $best[$id][1] && $url < $best[$id][0])) {
                $best[$id] = [$url, $count];
            }
        }
        return array_map(static fn (array $pair): string => $pair[0], $best);
    }

    private function latest(string $a, string $b): string
    {
        if ($a === '') {
            return $b;
        }
        if ($b === '') {
            return $a;
        }
        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    private function countersSince(): ?string
    {
        try {
            $conn = $this->groupResource->getConnection();
            $value = $conn->fetchOne(
                'SELECT MIN(hour_start) FROM ' . $conn->quoteIdentifier($this->hourlyTable())
            );
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function hourlyTable(): string
    {
        $table = $this->groupResource->getHourlyTable();
        return $table !== '' ? $table : ErrorGroupResource::HOURLY_TABLE;
    }

    private function fetchHourlyCounts(string $from, ?array $onlyGroupIds): array
    {
        $conn = $this->groupResource->getConnection();
        $select = $conn->select()
            ->from(
                $this->hourlyTable(),
                [
                    'group_id',
                    'cnt' => new Expression('SUM(occurrences)'),
                    'bot_cnt' => new Expression('SUM(bot_occurrences)'),
                ]
            )
            ->where('hour_start >= ?', $from)
            ->group('group_id');
        if ($onlyGroupIds !== null) {
            $select->where('group_id IN (?)', $onlyGroupIds);
        }
        return $conn->fetchAll($select);
    }

    private function fetchWindowCounts(string $since, ?array $onlyGroupIds, ?string $until = null): array
    {
        $conn = $this->eventResource->getConnection();
        $select = $conn->select()
            ->from(
                $this->eventResource->getMainTable(),
                [
                    'group_id',
                    'cnt' => new Expression('COUNT(*)'),
                    'first_at' => new Expression('MIN(created_at)'),
                    'last_at' => new Expression('MAX(created_at)'),
                ]
            )
            ->where('created_at >= ?', $since)
            ->group('group_id');
        if ($until !== null) {
            $select->where('created_at < ?', $until);
        }
        if ($onlyGroupIds !== null) {
            $select->where('group_id IN (?)', $onlyGroupIds);
        }
        return $conn->fetchAll($select);
    }

    private function fetchUserAgentRows(string $since, array $ids, ?string $until = null): array
    {
        $conn = $this->eventResource->getConnection();
        $rows = [];
        foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
            $flag = $conn->quoteInto('context LIKE ?', self::BOT_FLAG_LIKE);
            $select = $conn->select()
                ->from(
                    $this->eventResource->getMainTable(),
                    [
                        'group_id',
                        'user_agent',
                        'flagged' => new Expression('IF(' . $flag . ', 1, 0)'),
                        'cnt' => new Expression('COUNT(*)'),
                    ]
                )
                ->where('created_at >= ?', $since)
                ->where('group_id IN (?)', $chunk)
                ->group(['group_id', 'user_agent', 'flagged']);
            if ($until !== null) {
                $select->where('created_at < ?', $until);
            }
            foreach ($conn->fetchAll($select) as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function fetchUrlRows(string $since, array $ids): array
    {
        $conn = $this->eventResource->getConnection();
        $rows = [];
        foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
            $select = $conn->select()
                ->from(
                    $this->eventResource->getMainTable(),
                    [
                        'group_id',
                        'top_url' => new Expression('LEFT(url, 512)'),
                        'cnt' => new Expression('COUNT(*)'),
                    ]
                )
                ->where('created_at >= ?', $since)
                ->where('group_id IN (?)', $chunk)
                ->where("url IS NOT NULL AND url <> ''")
                ->group(['group_id', 'top_url']);
            foreach ($conn->fetchAll($select) as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function fetchGroups(array $ids): array
    {
        $conn = $this->groupResource->getConnection();
        $eventTable = $conn->quoteIdentifier($this->eventResource->getMainTable());
        $rows = [];
        foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
            $select = $conn->select()
                ->from(
                    ['g' => $this->groupResource->getMainTable()],
                    [
                        'group_id', 'source', 'severity', 'error_type', 'message', 'file', 'line',
                        'status', 'occurrence_count', 'first_seen_at', 'last_seen_at', 'store_id', 'line_unreliable',
                        'has_stack' => new Expression(
                            'IF(EXISTS(SELECT 1 FROM ' . $eventTable . ' AS e0 WHERE e0.group_id = g.group_id),'
                            . ' EXISTS(SELECT 1 FROM ' . $eventTable . ' AS e WHERE e.group_id = g.group_id'
                            . " AND e.stack_trace IS NOT NULL AND e.stack_trace <> ''), NULL)"
                        ),
                    ]
                )
                ->where('g.group_id IN (?)', $chunk);
            foreach ($conn->fetchAll($select) as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
