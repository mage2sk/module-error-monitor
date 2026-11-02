<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;

class ErrorRecorder
{
    public const INTERNAL_MARKER = '[PanthErrorMonitor]';

    public const EVENT_CAP_KEEP_NEWEST = 'newest';

    public const EVENT_CAP_KEEP_OLDEST = 'oldest';

    public const MAX_CONTEXT_BYTES = 60000;

    private const ECOSYSTEM_ALERT_PATTERN = '/^\[Panth[A-Z][A-Za-z0-9_]{0,40}\]\s+(?:BLOCKED|REJECTED|DENIED|REFUSED|DROPPED|QUARANTINED)\b/';

    private const DEFAULT_THROTTLE_WINDOW = 60;

    private const DEFAULT_MAX_EVENTS_PER_GROUP = 50;

    private const DEFAULT_MAX_NEW_JS_GROUPS_PER_HOUR = 100;

    private const CAP_TRIM_BATCH = 1000;

    private const MAX_CONTEXT_STRING = 500;

    private const SENSITIVE_PARAM_PATTERN =
        '/token|pass|pwd|secret|key|auth|session|sid|sig|hash|code|nonce|otp|email|mail|phone|telephone/';

    private const SENSITIVE_CONTEXT_PATTERN =
        '/pass|pwd|secret|token|api_?key|private_?key|auth|cookie|session|sessid|form_key|^sid$|card|cc_|cvv|cvc|email|phone|telephone/';

    private static bool $recording = false;

    private readonly NoiseFilter $noiseFilter;

    private readonly PathMapper $pathMapper;

    public function __construct(
        private readonly ErrorGroupResource $groupResource,
        private readonly ErrorEventResource $eventResource,
        private readonly Fingerprinter $fingerprinter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CaptureThrottle $throttle,
        private readonly NewGroupLimiter $newGroupLimiter,
        ?NoiseFilter $noiseFilter = null,
        ?PathMapper $pathMapper = null
    ) {
        $this->noiseFilter = $noiseFilter ?? new NoiseFilter($scopeConfig);
        $this->pathMapper = $pathMapper ?? new PathMapper();
    }

    public function record(ErrorPayload $payload): ?int
    {
        if (self::$recording) {
            return null;
        }
        self::$recording = true;
        try {
            if (trim($payload->message) === '' || $this->isIgnored($payload)) {
                return null;
            }

            $lineUnreliable = $this->pathMapper->isLineUnreliable($payload->file, $payload->line);
            $payload = $this->mapSourcePath($payload);
            $isBot = !empty($payload->context['is_bot']);

            $fingerprint = $this->fingerprinter->fingerprint(
                $payload->source,
                $payload->type,
                $payload->message,
                $payload->file,
                $payload->line
            );

            if ($payload->source === ErrorGroup::SOURCE_JS
                && !$this->groupExists($fingerprint)
                && !$this->newGroupLimiter->allow($this->intConfig(
                    'panth_errormonitor/js_capture/max_new_groups_per_hour',
                    self::DEFAULT_MAX_NEW_JS_GROUPS_PER_HOUR
                ))) {
                return null;
            }

            $increment = $this->throttle->register($fingerprint, $this->throttleWindow());
            if ($increment <= 0) {
                $this->countHourlyByFingerprint($fingerprint, $isBot);
                return null;
            }

            $groupId = $this->upsertGroup($fingerprint, $payload, $increment, $lineUnreliable);
            if ($groupId > 0) {
                $this->countHourly($groupId, $isBot);
                $this->storeEvent($groupId, $payload);
            }
            return $groupId > 0 ? $groupId : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            self::$recording = false;
        }
    }

    public function encodeContext(array $context): ?string
    {
        if ($context === []) {
            return null;
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;
        $context = $this->redactContext($context);
        $json = json_encode($context, $flags);
        if (is_string($json) && strlen($json) <= self::MAX_CONTEXT_BYTES) {
            return $json;
        }
        $json = json_encode($this->shrinkContext($context, self::MAX_CONTEXT_STRING), $flags);
        if (is_string($json) && strlen($json) <= self::MAX_CONTEXT_BYTES) {
            return $json;
        }
        $json = json_encode([
            'context_truncated' => true,
            'keys' => array_slice(array_map('strval', array_keys($context)), 0, 50),
        ], $flags);
        return is_string($json) ? $json : null;
    }

    private function upsertGroup(string $fingerprint, ErrorPayload $payload, int $increment, bool $lineUnreliable): int
    {
        $conn = $this->groupResource->getConnection();
        $table = $this->groupResource->getMainTable();
        $now = gmdate('Y-m-d H:i:s');

        $increment = max(1, $increment);

        $sql = 'INSERT INTO ' . $conn->quoteIdentifier($table)
            . ' (fingerprint, source, severity, error_type, message, file, line,'
            . ' status, occurrence_count, store_id, first_seen_at, last_seen_at, created_at, updated_at,'
            . ' line_unreliable)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ' . ErrorGroup::STATUS_NEW . ', ' . $increment . ', ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' group_id = LAST_INSERT_ID(group_id),'
            . ' occurrence_count = occurrence_count + ' . $increment . ','
            . ' last_seen_at = VALUES(last_seen_at),'
            . ' severity = VALUES(severity),'
            . ' message = VALUES(message),'
            . ' store_id = VALUES(store_id),'
            . ' line_unreliable = VALUES(line_unreliable),'
            . ' status = IF(status = ' . ErrorGroup::STATUS_RESOLVED
            . ', ' . ErrorGroup::STATUS_NEW . ', status),'
            . ' updated_at = VALUES(updated_at)';

        $bind = [
            $fingerprint,
            $this->cap($payload->source, 8),
            $this->normalizeSeverity($payload->severity),
            $payload->type !== '' ? $this->cap($payload->type, 191) : null,
            $this->cap($payload->message, 4000),
            $payload->file !== null ? $this->cap($payload->file, 512) : null,
            $payload->line,
            $payload->storeId,
            $now,
            $now,
            $now,
            $now,
            $lineUnreliable ? 1 : 0,
        ];

        $conn->query($sql, $bind);
        return (int)$conn->lastInsertId($table);
    }

    private function countHourly(int $groupId, bool $isBot): void
    {
        try {
            $conn = $this->groupResource->getConnection();
            $conn->query(
                'INSERT INTO ' . $conn->quoteIdentifier($this->hourlyTable())
                . ' (group_id, hour_start, occurrences, bot_occurrences) VALUES (?, ?, 1, ?)'
                . ' ON DUPLICATE KEY UPDATE occurrences = occurrences + 1,'
                . ' bot_occurrences = bot_occurrences + VALUES(bot_occurrences)',
                [$groupId, gmdate('Y-m-d H:00:00'), $isBot ? 1 : 0]
            );
        } catch (\Throwable $e) {
            return;
        }
    }

    private function countHourlyByFingerprint(string $fingerprint, bool $isBot): void
    {
        try {
            $conn = $this->groupResource->getConnection();
            $conn->query(
                'INSERT INTO ' . $conn->quoteIdentifier($this->hourlyTable())
                . ' (group_id, hour_start, occurrences, bot_occurrences)'
                . ' SELECT group_id, ?, 1, ? FROM ' . $conn->quoteIdentifier($this->groupResource->getMainTable())
                . ' WHERE fingerprint = ?'
                . ' ON DUPLICATE KEY UPDATE occurrences = occurrences + 1,'
                . ' bot_occurrences = bot_occurrences + VALUES(bot_occurrences)',
                [gmdate('Y-m-d H:00:00'), $isBot ? 1 : 0, $fingerprint]
            );
        } catch (\Throwable $e) {
            return;
        }
    }

    private function hourlyTable(): string
    {
        $table = $this->groupResource->getHourlyTable();
        return $table !== '' ? $table : ErrorGroupResource::HOURLY_TABLE;
    }

    private function storeEvent(int $groupId, ErrorPayload $payload): void
    {
        $cap = $this->intConfig('panth_errormonitor/general/max_events_per_group', self::DEFAULT_MAX_EVENTS_PER_GROUP);
        if ($cap <= 0) {
            $this->insertEvent($groupId, $payload);
            return;
        }
        $keepNewest = $this->keepNewestEvents();
        $count = $this->countEvents($groupId, $keepNewest ? $cap + self::CAP_TRIM_BATCH : $cap);
        if ($count < $cap) {
            $this->insertEvent($groupId, $payload);
            return;
        }
        if (!$keepNewest) {
            return;
        }
        $this->deleteOldestEvents($groupId, $count - $cap + 1);
        $this->insertEvent($groupId, $payload);
    }

    private function insertEvent(int $groupId, ErrorPayload $payload): void
    {
        $conn = $this->eventResource->getConnection();
        $table = $this->eventResource->getMainTable();

        $conn->insert($table, [
            'group_id'    => $groupId,
            'url'         => $payload->url !== null ? $this->cap($this->redactUrl($payload->url), 2048) : null,
            'referer'     => $payload->referer !== null ? $this->cap($this->redactUrl($payload->referer), 2048) : null,
            'user_agent'  => $payload->userAgent !== null ? $this->cap($payload->userAgent, 512) : null,
            'ip'          => $payload->ip !== null ? $this->cap($payload->ip, 45) : null,
            'http_method' => $payload->httpMethod !== null ? $this->cap($payload->httpMethod, 10) : null,
            'stack_trace' => $payload->stackTrace !== null ? $this->cap($payload->stackTrace, 60000) : null,
            'context'     => $this->encodeContext($payload->context),
            'store_id'    => $payload->storeId,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function isIgnored(ErrorPayload $payload): bool
    {
        if ($this->scopeConfig->isSetFlag('panth_errormonitor/general/filter_ecosystem_alerts')
            && preg_match(self::ECOSYSTEM_ALERT_PATTERN, $payload->message) === 1) {
            return true;
        }

        return $this->noiseFilter->isIgnored(
            $payload->message,
            $payload->file,
            $payload->type,
            $payload->stackTrace
        );
    }

    private function mapSourcePath(ErrorPayload $payload): ErrorPayload
    {
        if (!$this->pathMapper->isPreprocessed($payload->file)) {
            return $payload;
        }
        return new ErrorPayload(
            source: $payload->source,
            severity: $payload->severity,
            type: $payload->type,
            message: $payload->message,
            file: $this->pathMapper->toSource($payload->file),
            line: $payload->line,
            stackTrace: $payload->stackTrace,
            url: $payload->url,
            referer: $payload->referer,
            userAgent: $payload->userAgent,
            ip: $payload->ip,
            httpMethod: $payload->httpMethod,
            context: $payload->context + [
                'preprocessed_file' => $payload->file,
                'line_note' => PathMapper::LINE_NOTE,
            ],
            storeId: $payload->storeId
        );
    }

    private function keepNewestEvents(): bool
    {
        return (string)$this->scopeConfig->getValue('panth_errormonitor/general/event_cap_mode')
            === self::EVENT_CAP_KEEP_NEWEST;
    }

    private function deleteOldestEvents(int $groupId, int $count): void
    {
        $count = max(1, min($count, self::CAP_TRIM_BATCH + 1));
        $conn = $this->eventResource->getConnection();
        $conn->query(
            'DELETE FROM ' . $conn->quoteIdentifier($this->eventResource->getMainTable())
            . ' WHERE group_id = ? ORDER BY created_at ASC, event_id ASC LIMIT ' . $count,
            [$groupId]
        );
    }

    private function groupExists(string $fingerprint): bool
    {
        $conn = $this->groupResource->getConnection();
        $id = $conn->fetchOne(
            'SELECT group_id FROM ' . $conn->quoteIdentifier($this->groupResource->getMainTable())
            . ' WHERE fingerprint = ?',
            [$fingerprint]
        );
        return (int)$id > 0;
    }

    private function countEvents(int $groupId, int $limit): int
    {
        $conn = $this->eventResource->getConnection();
        $count = $conn->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $conn->quoteIdentifier($this->eventResource->getMainTable())
            . ' WHERE group_id = ? LIMIT ' . max(1, $limit) . ') AS capped',
            [$groupId]
        );
        return (int)$count;
    }

    private function intConfig(string $path, int $default): int
    {
        $raw = $this->scopeConfig->getValue($path);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return max(0, (int)$raw);
    }

    private function throttleWindow(): int
    {
        $raw = $this->scopeConfig->getValue('panth_errormonitor/php_capture/throttle_window_seconds');
        if ($raw === null || $raw === '') {
            return self::DEFAULT_THROTTLE_WINDOW;
        }
        return max(0, (int)$raw);
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        return isset(Severity::RANKS[$severity]) ? $severity : 'error';
    }

    private function shrinkContext(array $context, int $max, int $depth = 0): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value) && mb_strlen($value, 'UTF-8') > $max) {
                $context[$key] = mb_substr($value, 0, $max, 'UTF-8') . '...';
            } elseif (is_array($value)) {
                $context[$key] = $depth >= 4 ? '[nested data removed]' : $this->shrinkContext($value, $max, $depth + 1);
            }
        }
        return $context;
    }

    private function redactContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match(self::SENSITIVE_CONTEXT_PATTERN, strtolower((string)$key)) === 1) {
                $context[$key] = 'redacted';
            } elseif (is_array($value)) {
                $context[$key] = $this->redactContext($value);
            }
        }
        return $context;
    }

    private function redactUrl(string $url): string
    {
        $url = explode('#', $url, 2)[0];
        $parts = explode('?', $url, 2);
        if (!isset($parts[1]) || $parts[1] === '') {
            return $parts[0];
        }
        $pairs = explode('&', $parts[1]);
        foreach ($pairs as $i => $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $name = strtolower(substr($pair, 0, $eq));
            if (preg_match(self::SENSITIVE_PARAM_PATTERN, $name) === 1) {
                $pairs[$i] = substr($pair, 0, $eq) . '=redacted';
            }
        }
        return $parts[0] . '?' . implode('&', $pairs);
    }

    private function cap(string $value, int $max): string
    {
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        if (mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max, 'UTF-8');
    }
}
