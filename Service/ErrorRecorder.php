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

    private const ECOSYSTEM_ALERT_PATTERN = '/^\[Panth[A-Z][A-Za-z0-9_]{0,40}\]\s+(?:BLOCKED|REJECTED|DENIED|REFUSED|DROPPED|QUARANTINED)\b/';

    private static bool $recording = false;

    private const DEFAULT_THROTTLE_WINDOW = 60;

    public function __construct(
        private readonly ErrorGroupResource $groupResource,
        private readonly ErrorEventResource $eventResource,
        private readonly Fingerprinter $fingerprinter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CaptureThrottle $throttle
    ) {
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

            $fingerprint = $this->fingerprinter->fingerprint(
                $payload->source,
                $payload->type,
                $payload->message,
                $payload->file,
                $payload->line
            );

            $increment = $this->throttle->register($fingerprint, $this->throttleWindow());
            if ($increment <= 0) {
                return null;
            }

            $groupId = $this->upsertGroup($fingerprint, $payload, $increment);
            if ($groupId > 0) {
                $this->insertEvent($groupId, $payload);
            }
            return $groupId > 0 ? $groupId : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            self::$recording = false;
        }
    }

    private function upsertGroup(string $fingerprint, ErrorPayload $payload, int $increment): int
    {
        $conn = $this->groupResource->getConnection();
        $table = $this->groupResource->getMainTable();
        $now = gmdate('Y-m-d H:i:s');

        $increment = max(1, $increment);

        $sql = 'INSERT INTO ' . $conn->quoteIdentifier($table)
            . ' (fingerprint, source, severity, error_type, message, file, line,'
            . ' status, occurrence_count, store_id, first_seen_at, last_seen_at, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ' . ErrorGroup::STATUS_NEW . ', ' . $increment . ', ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' group_id = LAST_INSERT_ID(group_id),'
            . ' occurrence_count = occurrence_count + ' . $increment . ','
            . ' last_seen_at = VALUES(last_seen_at),'
            . ' severity = VALUES(severity),'
            . ' message = VALUES(message),'
            . ' store_id = VALUES(store_id),'

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
        ];

        $conn->query($sql, $bind);
        return (int)$conn->lastInsertId($table);
    }

    private function insertEvent(int $groupId, ErrorPayload $payload): void
    {
        $conn = $this->eventResource->getConnection();
        $table = $this->eventResource->getMainTable();

        $context = $payload->context !== []
            ? json_encode($payload->context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
            : null;

        $conn->insert($table, [
            'group_id'    => $groupId,
            'url'         => $payload->url !== null ? $this->cap($payload->url, 2048) : null,
            'referer'     => $payload->referer !== null ? $this->cap($payload->referer, 2048) : null,
            'user_agent'  => $payload->userAgent !== null ? $this->cap($payload->userAgent, 512) : null,
            'ip'          => $payload->ip !== null ? $this->cap($payload->ip, 45) : null,
            'http_method' => $payload->httpMethod !== null ? $this->cap($payload->httpMethod, 10) : null,
            'stack_trace' => $payload->stackTrace !== null ? $this->cap($payload->stackTrace, 60000) : null,
            'context'     => $context !== false ? $context : null,
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

        $raw = (string)$this->scopeConfig->getValue('panth_errormonitor/general/ignore_patterns');
        if ($raw === '') {
            $raw = (string)$this->scopeConfig->getValue('panth_errormonitor/js_capture/ignore_patterns');
        }
        if ($raw === '') {
            return false;
        }
        $haystack = mb_strtolower(
            $payload->message
            . "\n" . ($payload->file ?? '')
            . "\n" . $payload->type
            . "\n" . ($payload->stackTrace ?? '')
        );
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && str_contains($haystack, mb_strtolower($line))) {
                return true;
            }
        }
        return false;
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

    private function cap(string $value, int $max): string
    {
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        if (mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max, 'UTF-8');
    }
}
