<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Typed configuration reader for Panth Error Monitor. Extends the shared
 * Panth_Core AbstractConfig so behaviour stays consistent across the suite.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Helper;

use Panth\Core\Helper\AbstractConfig;

class Config extends AbstractConfig
{
    private const XML_PATH = 'panth_errormonitor';

    /**
     * @inheritDoc
     */
    protected function getConfigValue(string $group, string $field, $storeId = null)
    {
        return $this->getConfig(self::XML_PATH . '/' . $group . '/' . $field, $storeId);
    }

    /**
     * Master switch.
     */
    public function isEnabled($storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH . '/general/enabled', $storeId);
    }

    /* ----------------------------------------------------------------- PHP */

    public function isPhpCaptureEnabled($storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->isSetFlag(self::XML_PATH . '/php_capture/enabled', $storeId);
    }

    public function getPhpMinSeverity($storeId = null): string
    {
        return (string)($this->getConfigValue('php_capture', 'min_severity', $storeId) ?: 'error');
    }

    /**
     * Window (seconds) over which repeat occurrences of the same error are
     * coalesced into a single database write. 0 disables coalescing.
     */
    public function getPhpThrottleWindowSeconds($storeId = null): int
    {
        $raw = $this->getConfigValue('php_capture', 'throttle_window_seconds', $storeId);
        if ($raw === null || $raw === '') {
            return 60;
        }
        return max(0, (int)$raw);
    }

    /* ------------------------------------------------------------------ JS */

    public function isJsCaptureEnabled($storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->isSetFlag(self::XML_PATH . '/js_capture/enabled', $storeId);
    }

    public function getJsSampleRate($storeId = null): int
    {
        $rate = (int)$this->getConfigValue('js_capture', 'sample_rate', $storeId);
        if ($rate < 0) {
            return 0;
        }
        return $rate > 100 ? 100 : $rate;
    }

    public function getJsRateLimitPerMinute($storeId = null): int
    {
        $limit = (int)$this->getConfigValue('js_capture', 'rate_limit_per_minute', $storeId);
        return $limit > 0 ? $limit : 30;
    }

    public function getJsMaxBodyBytes($storeId = null): int
    {
        $kb = (int)$this->getConfigValue('js_capture', 'max_body_kb', $storeId);
        $kb = $kb > 0 ? $kb : 16;
        return $kb * 1024;
    }

    /**
     * Lower-cased ignore substrings, applied at capture time across the
     * error's message, file path, error class FQN and stack trace.
     *
     * Reads the canonical location (general/ignore_patterns) and falls back
     * to the legacy location (js_capture/ignore_patterns) so customers that
     * upgrade before MigrateIgnorePatternsToGeneral runs keep their list.
     *
     * @return string[]
     */
    public function getIgnorePatterns($storeId = null): array
    {
        $raw = (string)$this->getConfigValue('general', 'ignore_patterns', $storeId);
        if ($raw === '') {
            $raw = (string)$this->getConfigValue('js_capture', 'ignore_patterns', $storeId);
        }
        if ($raw === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = mb_strtolower($line);
            }
        }
        return $out;
    }

    /* --------------------------------------------------------------- Email */

    public function isEmailEnabled($storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->isSetFlag(self::XML_PATH . '/email/enabled', $storeId);
    }

    public function getEmailMode($storeId = null): string
    {
        return (string)($this->getConfigValue('email', 'mode', $storeId) ?: 'daily_summary');
    }

    /**
     * Hour of day (0-23, UTC) at which the daily summary is sent.
     */
    public function getEmailSendHour($storeId = null): int
    {
        $hour = (int)$this->getConfigValue('email', 'send_hour', $storeId);
        if ($hour < 0) {
            return 0;
        }
        return $hour > 23 ? 23 : $hour;
    }

    /**
     * @return string[] Validated recipient addresses.
     */
    public function getEmailRecipients($storeId = null): array
    {
        $raw = (string)$this->getConfigValue('email', 'recipients', $storeId);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[,\r\n]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $out[$part] = $part;
            }
        }
        return array_values($out);
    }

    public function getEmailSender($storeId = null): string
    {
        return (string)($this->getConfigValue('email', 'sender', $storeId) ?: 'general');
    }

    public function getEmailMinSeverity($storeId = null): string
    {
        return (string)($this->getConfigValue('email', 'min_severity', $storeId) ?: 'error');
    }

    public function getEmailMaxPerRun($storeId = null): int
    {
        $max = (int)$this->getConfigValue('email', 'max_per_run', $storeId);
        return $max > 0 ? $max : 20;
    }

    /* ------------------------------------------------------------- Privacy */

    public function shouldStoreIp($storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH . '/privacy/store_ip', $storeId);
    }

    public function shouldAnonymizeIp($storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH . '/privacy/anonymize_ip', $storeId);
    }

    /* ----------------------------------------------------------- Retention */

    public function getEventRetentionDays($storeId = null): int
    {
        $days = (int)$this->getConfigValue('retention', 'event_days', $storeId);
        return $days > 0 ? $days : 30;
    }

    public function getResolvedGroupRetentionDays($storeId = null): int
    {
        $days = (int)$this->getConfigValue('retention', 'resolved_group_days', $storeId);
        return $days > 0 ? $days : 90;
    }
}
