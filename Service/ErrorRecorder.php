<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * The "smart" core. Turns an ErrorPayload into:
 *   1. an atomic UPSERT into panth_error_group keyed on the fingerprint —
 *      so repeat occurrences increment a counter instead of inserting rows;
 *   2. one detail row in panth_error_event linked to that group.
 *
 * HARD GUARANTEES (this runs inside the system log handler, so it must never
 * make things worse):
 *   - Re-entrancy guard: if persisting an error itself triggers a logged
 *     error (e.g. a DB deadlock), we do NOT recurse.
 *   - Every public path is wrapped so a failure is swallowed — capturing an
 *     error must never throw a new one.
 *   - All string fields are length-capped to their column size.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;

class ErrorRecorder
{
    /** Marker prefix on our own warnings so the handler can skip them. */
    public const INTERNAL_MARKER = '[PanthErrorMonitor]';

    /**
     * Operational-alert convention used family-wide by sibling security /
     * firewall modules in this ecosystem: a single-bracket vendor-module tag
     * followed by an action verb. These are events the operator took action
     * on, not defects, and they bury the real-bug signal in the grid.
     * Match scope intentionally does NOT name any specific module so the
     * open-source code stays generic (per public-docs-generic memory).
     *
     * Examples it suppresses (when general/filter_ecosystem_alerts is on):
     *   [PanthMalwareScanner] BLOCKED frontend dispatch: ...
     *   [PanthFirewall] REJECTED request from ip ...
     *   [PanthBotShield] DENIED user-agent ...
     */
    private const ECOSYSTEM_ALERT_PATTERN = '/^\[Panth[A-Z][A-Za-z0-9_]{0,40}\]\s+(?:BLOCKED|REJECTED|DENIED|REFUSED|DROPPED|QUARANTINED)\b/';

    /** Re-entrancy flag shared across all instances. */
    private static bool $recording = false;

    public function __construct(
        private readonly ErrorGroupResource $groupResource,
        private readonly ErrorEventResource $eventResource,
        private readonly Fingerprinter $fingerprinter,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Persist one occurrence. Returns the group id, or null if skipped/failed.
     */
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

            $groupId = $this->upsertGroup($fingerprint, $payload);
            if ($groupId > 0) {
                $this->insertEvent($groupId, $payload);
            }
            return $groupId > 0 ? $groupId : null;
        } catch (\Throwable $e) {
            // Never let error-capture break the request. No re-logging here —
            // that would risk a loop even with the guard.
            return null;
        } finally {
            self::$recording = false;
        }
    }

    /**
     * Atomic INSERT ... ON DUPLICATE KEY UPDATE on the fingerprint unique key.
     * The LAST_INSERT_ID(group_id) trick returns the group id on both insert
     * and update so we always have it for the event row.
     */
    private function upsertGroup(string $fingerprint, ErrorPayload $payload): int
    {
        $conn = $this->groupResource->getConnection();
        $table = $this->groupResource->getMainTable();
        $now = gmdate('Y-m-d H:i:s');

        $sql = 'INSERT INTO ' . $conn->quoteIdentifier($table)
            . ' (fingerprint, source, severity, error_type, message, file, line,'
            . ' status, occurrence_count, store_id, first_seen_at, last_seen_at, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ' . ErrorGroup::STATUS_NEW . ', 1, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' group_id = LAST_INSERT_ID(group_id),'
            . ' occurrence_count = occurrence_count + 1,'
            . ' last_seen_at = VALUES(last_seen_at),'
            . ' severity = VALUES(severity),'
            . ' message = VALUES(message),'
            . ' store_id = VALUES(store_id),'
            // A resolved error that recurs is a regression — re-open it.
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

    /**
     * Drop the error if (a) the ecosystem-alert auto-filter is on AND the
     * message matches the family-wide BLOCKED/REJECTED pattern, OR (b) any
     * configured substring appears in its message, file path, error class
     * FQN or stack trace — case-insensitive. Reads the canonical config
     * path (general/ignore_patterns) and falls back to the 1.4.x location
     * (js_capture/ignore_patterns) so admins who upgrade before
     * MigrateIgnorePatternsToGeneral runs keep their list.
     */
    private function isIgnored(ErrorPayload $payload): bool
    {
        // (a) Sibling-module operational alerts. Default-on; checked first
        // because it's a single bounded-regex test and short-circuits the
        // textarea scan for the common case.
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

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        return isset(Severity::RANKS[$severity]) ? $severity : 'error';
    }

    /**
     * Multibyte-safe, control-character-stripped truncation.
     */
    private function cap(string $value, int $max): string
    {
        // Strip control chars (keep tab/newline) so stored data stays printable.
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        if (mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max, 'UTF-8');
    }
}
