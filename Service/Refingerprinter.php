<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Psr\Log\LoggerInterface;

class Refingerprinter
{
    public const GRAPHQL_CLIENT_TYPES = [
        'magento\framework\graphql\exception\graphqlinputexception',
        'magento\framework\graphql\exception\graphqlauthorizationexception',
        'magento\framework\graphql\exception\graphqlauthenticationexception',
        'magento\framework\graphql\exception\graphqlnosuchentityexception',
        'magento\framework\graphql\exception\graphqlalreadyexistsexception',
    ];

    private const GRAPHQL_ERROR_TYPE = 'graphql\error\error';

    private const GRAPHQL_VALIDATION_MESSAGE = '/^(?:GraphQL\\\\Error\\\\Error:\s*)?(?:Cannot query field|Unknown argument'
        . '|Unknown type|Unknown fragment|Unknown directive|Syntax Error|Variable "\$|Field "[^"]+" argument'
        . '|Expected type|Expected value of type|Field "[^"]+" of type "[^"]+" must have a selection'
        . '|Field "[^"]+" must not have a selection|Argument "[^"]+" has invalid value|There can be only one'
        . '|Fragment "[^"]+"|Directive "[^"]+")/i';

    private const REPORT_WRAPPER_CLIENT = '/^Report ID: graph-ql-[0-9a-f]{6,32}; Message: (?:The current customer'
        . ' isn\'t authorized|The current user cannot perform operations on cart|The request is allowed for logged in'
        . ' customer)/i';

    private const GENERIC_TYPES = ['', 'main', 'report', 'error', 'exception', 'throwable'];

    private const STATUS_PRIORITY = [
        ErrorGroup::STATUS_NEW => 3,
        ErrorGroup::STATUS_IGNORED => 2,
        ErrorGroup::STATUS_RESOLVED => 1,
    ];

    private const COLUMNS = [
        'group_id', 'fingerprint', 'source', 'severity', 'error_type', 'message', 'file', 'line', 'status',
        'occurrence_count', 'first_seen_at', 'last_seen_at', 'emailed_count', 'last_emailed_date',
    ];

    private const ID_CHUNK = 1000;

    private const DEFAULT_EVENT_CAP = 50;

    public function __construct(
        private readonly ErrorGroupResource $groupResource,
        private readonly ErrorEventResource $eventResource,
        private readonly Fingerprinter $fingerprinter,
        private readonly PathMapper $pathMapper,
        private readonly LoggerInterface $logger,
        private readonly ?ScopeConfigInterface $scopeConfig = null
    ) {
    }

    public function run(bool $dryRun = false): array
    {
        $plan = $this->plan($this->loadGroups(false));
        $stats = $this->stats($plan, $dryRun);
        if ($plan['merges'] === [] && $plan['updates'] === []) {
            return $stats;
        }
        if ($dryRun) {
            $stats['events_moved'] = $this->countEvents(array_merge([], ...array_values($plan['merges'])));
            $cap = $this->eventCap();
            if ($cap > 0) {
                foreach ($plan['merges'] as $survivorId => $peerIds) {
                    $total = $this->countEvents(array_merge([(int)$survivorId], $peerIds));
                    $stats['events_trimmed'] += max(0, $total - $cap);
                }
            }
            return $stats;
        }

        $conn = $this->groupResource->getConnection();
        $groupTable = $this->groupResource->getMainTable();
        $eventTable = $this->eventResource->getMainTable();

        $conn->beginTransaction();
        try {
            $plan = $this->plan($this->loadGroups(true));
            $stats = $this->stats($plan, false);

            foreach ($plan['merges'] as $survivorId => $peerIds) {
                $stats['events_moved'] += (int)$conn->update(
                    $eventTable,
                    ['group_id' => (int)$survivorId],
                    ['group_id IN (?)' => $peerIds]
                );
                $this->mergeHourlyCounters($conn, (int)$survivorId, $peerIds);
                $conn->delete($groupTable, ['group_id IN (?)' => $peerIds]);
                $stats['events_trimmed'] += $this->trimToCap($conn, (int)$survivorId);
            }

            foreach ($plan['updates'] as $groupId => $update) {
                if (isset($update['fingerprint'])) {
                    $conn->update(
                        $groupTable,
                        ['fingerprint' => hash('sha256', 'refingerprint-pending-' . $groupId)],
                        ['group_id = ?' => (int)$groupId]
                    );
                }
            }

            foreach ($plan['updates'] as $groupId => $update) {
                $conn->update($groupTable, $update, ['group_id = ?' => (int)$groupId]);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->warning('[PanthErrorMonitor] refingerprint rolled back: ' . $e->getMessage());
            throw new RefingerprintRolledBackException($e->getMessage(), (int)$e->getCode(), $e);
        }
        return $stats;
    }

    public function plan(array $groups): array
    {
        usort($groups, static function (array $a, array $b): int {
            $fa = (string)($a['first_seen_at'] ?? '');
            $fb = (string)($b['first_seen_at'] ?? '');
            if ($fa !== $fb) {
                if ($fa === '') {
                    return 1;
                }
                if ($fb === '') {
                    return -1;
                }
                return strcmp($fa, $fb);
            }
            return (int)$a['group_id'] <=> (int)$b['group_id'];
        });

        $buckets = [];
        $computed = [];
        foreach ($groups as $group) {
            $id = (int)$group['group_id'];
            $type = (string)($group['error_type'] ?? '');
            if (in_array(strtolower(trim($type)), self::GENERIC_TYPES, true)) {
                $mined = $this->fingerprinter->extractType((string)($group['message'] ?? ''));
                if ($mined !== null && $mined !== '') {
                    $type = $mined;
                }
            }
            $file = isset($group['file']) ? (string)$group['file'] : null;
            $mappedFile = $this->pathMapper->toSource($file);
            $line = isset($group['line']) && $group['line'] !== null ? (int)$group['line'] : null;
            $fingerprint = $this->fingerprinter->fingerprint(
                (string)($group['source'] ?? ''),
                $type,
                (string)($group['message'] ?? ''),
                $mappedFile,
                $line
            );
            $computed[$id] = ['fingerprint' => $fingerprint, 'error_type' => $type, 'file' => $mappedFile];
            $buckets[$fingerprint][] = $group;
        }

        $merges = [];
        $updates = [];
        $reclassified = 0;
        $reclassify = $this->reclassifyEnabled();
        foreach ($buckets as $members) {
            $survivor = $members[0];
            $survivorId = (int)$survivor['group_id'];
            $target = $computed[$survivorId];
            if (count($members) > 1) {
                $merges[$survivorId] = array_map(static fn (array $g): int => (int)$g['group_id'], array_slice($members, 1));
                $target += $this->mergedAggregates($members);
            }
            $severity = strtolower((string)($target['severity'] ?? $survivor['severity'] ?? 'error'));
            if ($reclassify
                && $severity !== 'notice'
                && $this->isGraphQlClientGroup((string)$target['error_type'], (string)($survivor['message'] ?? ''))) {
                $target['severity'] = 'notice';
                $reclassified++;
            }
            $diff = [];
            foreach ($target as $column => $value) {
                $current = $survivor[$column] ?? null;
                if ($this->differs($current, $value)) {
                    $diff[$column] = $value;
                }
            }
            if ($diff !== []) {
                $updates[$survivorId] = $diff;
            }
        }

        return [
            'scanned' => count($groups),
            'merges' => $merges,
            'updates' => $updates,
            'reclassified' => $reclassified,
        ];
    }

    public function isGraphQlClientGroup(string $type, string $message): bool
    {
        $type = strtolower(ltrim(trim($type), '\\'));
        if (in_array($type, self::GRAPHQL_CLIENT_TYPES, true)) {
            return true;
        }
        if ($type === self::GRAPHQL_ERROR_TYPE) {
            return preg_match(self::GRAPHQL_VALIDATION_MESSAGE, trim($message)) === 1;
        }
        return preg_match(self::REPORT_WRAPPER_CLIENT, trim($message)) === 1;
    }

    public function mergedAggregates(array $members): array
    {
        $occurrences = 0;
        $emailed = 0;
        $first = null;
        $last = null;
        $lastEmailed = null;
        $severity = null;
        $status = null;
        foreach ($members as $member) {
            $occurrences += (int)($member['occurrence_count'] ?? 0);
            $emailed = max($emailed, (int)($member['emailed_count'] ?? 0));
            $first = $this->pick($first, $member['first_seen_at'] ?? null, -1);
            $last = $this->pick($last, $member['last_seen_at'] ?? null, 1);
            $lastEmailed = $this->pick($lastEmailed, $member['last_emailed_date'] ?? null, 1);
            $memberSeverity = strtolower((string)($member['severity'] ?? 'error'));
            if ($severity === null || Severity::rank($memberSeverity) > Severity::rank($severity)) {
                $severity = $memberSeverity;
            }
            $memberStatus = (int)($member['status'] ?? ErrorGroup::STATUS_NEW);
            if ($status === null
                || (self::STATUS_PRIORITY[$memberStatus] ?? 0) > (self::STATUS_PRIORITY[$status] ?? 0)) {
                $status = $memberStatus;
            }
        }
        return [
            'occurrence_count' => $occurrences,
            'first_seen_at' => $first,
            'last_seen_at' => $last,
            'severity' => $severity ?? 'error',
            'status' => $status ?? ErrorGroup::STATUS_NEW,
            'emailed_count' => $emailed,
            'last_emailed_date' => $lastEmailed,
        ];
    }

    private function stats(array $plan, bool $dryRun): array
    {
        return [
            'dry_run' => $dryRun,
            'scanned' => $plan['scanned'],
            'merged_groups' => count($plan['merges']),
            'deleted_groups' => array_sum(array_map('count', $plan['merges'])),
            'updated_groups' => count($plan['updates']),
            'reclassified_groups' => (int)($plan['reclassified'] ?? 0),
            'events_moved' => 0,
            'events_trimmed' => 0,
            'merges' => $plan['merges'],
        ];
    }

    private function reclassifyEnabled(): bool
    {
        if ($this->scopeConfig === null) {
            return true;
        }
        $mode = strtolower(trim((string)$this->scopeConfig->getValue(GraphQlClientErrorFilter::XML_MODE)));
        return $mode !== GraphQlClientErrorFilter::MODE_RECORD;
    }

    private function countEvents(array $groupIds): int
    {
        if ($groupIds === []) {
            return 0;
        }
        $conn = $this->groupResource->getConnection();
        $table = $conn->quoteIdentifier($this->eventResource->getMainTable());
        $total = 0;
        foreach (array_chunk(array_map('intval', $groupIds), self::ID_CHUNK) as $chunk) {
            $total += (int)$conn->fetchOne(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE group_id IN (' . implode(',', $chunk) . ')'
            );
        }
        return $total;
    }

    private function mergeHourlyCounters(AdapterInterface $conn, int $survivorId, array $peerIds): void
    {
        $peerIds = array_values(array_filter(array_map('intval', $peerIds)));
        if ($peerIds === []) {
            return;
        }
        $table = $this->groupResource->getHourlyTable();
        $table = $conn->quoteIdentifier($table !== '' ? $table : ErrorGroupResource::HOURLY_TABLE);
        $conn->query(
            'INSERT INTO ' . $table . ' (group_id, hour_start, occurrences, bot_occurrences)'
            . ' SELECT agg.group_id, agg.hour_start, agg.occurrences, agg.bot_occurrences FROM ('
            . 'SELECT ' . $survivorId . ' AS group_id, hour_start, SUM(occurrences) AS occurrences,'
            . ' SUM(bot_occurrences) AS bot_occurrences FROM ' . $table
            . ' WHERE group_id IN (' . implode(',', $peerIds) . ') GROUP BY hour_start) AS agg'
            . ' ON DUPLICATE KEY UPDATE occurrences = ' . $table . '.occurrences + VALUES(occurrences),'
            . ' bot_occurrences = ' . $table . '.bot_occurrences + VALUES(bot_occurrences)'
        );
    }

    private function eventCap(): int
    {
        if ($this->scopeConfig === null) {
            return 0;
        }
        $raw = $this->scopeConfig->getValue('panth_errormonitor/general/max_events_per_group');
        return ($raw === null || $raw === '') ? self::DEFAULT_EVENT_CAP : max(0, (int)$raw);
    }

    private function trimToCap(AdapterInterface $conn, int $groupId): int
    {
        $cap = $this->eventCap();
        if ($cap <= 0) {
            return 0;
        }
        $table = $conn->quoteIdentifier($this->eventResource->getMainTable());
        $count = (int)$conn->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE group_id = ?', [$groupId]);
        if ($count <= $cap) {
            return 0;
        }
        $keepNewest = (string)$this->scopeConfig->getValue('panth_errormonitor/general/event_cap_mode')
            === ErrorRecorder::EVENT_CAP_KEEP_NEWEST;
        $direction = $keepNewest ? 'ASC' : 'DESC';
        $excess = $count - $cap;
        $conn->query(
            'DELETE FROM ' . $table . ' WHERE group_id = ? ORDER BY created_at ' . $direction
            . ', event_id ' . $direction . ' LIMIT ' . $excess,
            [$groupId]
        );
        return $excess;
    }

    private function pick(?string $current, $candidate, int $direction): ?string
    {
        if ($candidate === null || $candidate === '') {
            return $current;
        }
        $candidate = (string)$candidate;
        if ($current === null) {
            return $candidate;
        }
        $cmp = strcmp($candidate, $current);
        return ($direction < 0 ? $cmp < 0 : $cmp > 0) ? $candidate : $current;
    }

    private function differs($current, $value): bool
    {
        if ($current === null || $value === null) {
            return $current !== $value;
        }
        return (string)$current !== (string)$value;
    }

    private function loadGroups(bool $forUpdate): array
    {
        $conn = $this->groupResource->getConnection();
        $select = $conn->select();
        $select->from($this->groupResource->getMainTable(), self::COLUMNS);
        if ($forUpdate) {
            $select->forUpdate(true);
        }
        return $conn->fetchAll($select);
    }
}
