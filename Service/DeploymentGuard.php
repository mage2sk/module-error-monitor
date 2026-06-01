<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Decides whether error capture is currently suspended. Four signals, in
 * order of precedence:
 *
 *   1. Magento's own MaintenanceMode — the canonical "we're deploying" flag.
 *   2. An explicit, auto-expiring pause flag set by
 *      `bin/magento panth:errormonitor:pause [--minutes=N]`.
 *   3. **Filesystem mtime DELTA detection** (rewritten in 1.5.3 — see below).
 *
 * Every check is wrapped — a failure in this service must NEVER break error
 * capture (always falls back to "not suspended").
 *
 * ─── Delta detection vs the old sliding-window approach ──────────────────
 *
 * 1.5.1 used a sliding window: "if any watched path's mtime is within the
 * last N minutes, suspend." That broke on environments where a watched
 * directory (especially `generated/code/`) has its mtime continuously
 * refreshed by on-demand interceptor generation or cron-time plugin work —
 * the N-minute window kept restarting and capture was silenced forever.
 *
 * 1.5.3 stores the LAST SEEN mtimes in a Flag and only suspends when a
 * path's mtime is **strictly newer than what we recorded last time**. That
 * is the real "a deploy just happened" signal:
 *
 *   - First-ever observation     → baseline saved, NOT suspended.
 *   - mtimes unchanged           → NOT suspended.
 *   - mtime increased            → suspend until (newest_change + window),
 *                                  baseline updated, suspension expiry
 *                                  cached in PAUSE_UNTIL_FLAG so it does
 *                                  not vanish on the next check.
 *   - Change observed but window → just update baseline, NOT suspended.
 *     already elapsed (eg cron-
 *     only environments)
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\FlagManager;

class DeploymentGuard
{
    /** Explicit-pause flag, value = epoch at which the pause expires. */
    public const PAUSE_FLAG = 'panth_errormonitor_capture_paused_until';

    /** Auto-pause-active flag, value = epoch at which the auto-pause expires. */
    public const AUTO_PAUSE_UNTIL_FLAG = 'panth_errormonitor_autopause_until';

    /** Baseline of last-observed deploy-marker mtimes (path => epoch). */
    public const DEPLOY_MTIMES_FLAG = 'panth_errormonitor_deploy_mtimes';

    private const AUTO_PAUSE_CONFIG = 'panth_errormonitor/general/auto_pause_window_minutes';
    private const DEFAULT_WINDOW_MINUTES = 5;
    private const MAX_WINDOW_MINUTES = 120;

    /** Per-request cache for the auto-pause check. */
    private ?bool $autoDetectedCache = null;

    /** Per-request cache of "why" — populated lazily for status output. */
    private ?string $autoDetectedReason = null;

    public function __construct(
        private readonly MaintenanceMode $maintenanceMode,
        private readonly FlagManager $flagManager,
        private readonly DirectoryList $directoryList,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * True when capture should be skipped for this request / log record.
     */
    public function isCaptureSuspended(): bool
    {
        try {
            if ($this->maintenanceMode->isOn()) {
                return true;
            }
            $pausedUntil = (int)$this->flagManager->getFlagData(self::PAUSE_FLAG);
            if ($pausedUntil > 0) {
                if ($pausedUntil > time()) {
                    return true;
                }
                $this->flagManager->deleteFlag(self::PAUSE_FLAG);
            }
            return $this->isInsideAutoDetectedDeployWindow();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Filesystem-mtime delta detect — see class docblock. Wrapped in a
     * try/catch by the caller; any failure inside returns "not suspended".
     */
    private function isInsideAutoDetectedDeployWindow(): bool
    {
        if ($this->autoDetectedCache !== null) {
            return $this->autoDetectedCache;
        }
        $minutes = $this->resolveWindowMinutes();
        if ($minutes <= 0) {
            $this->autoDetectedReason = 'disabled (window = 0)';
            return $this->autoDetectedCache = false;
        }
        $now = time();

        // Fast path: if we already established a pause window earlier this
        // request cycle and it's still in effect, short-circuit.
        $autoPauseUntil = (int)$this->flagManager->getFlagData(self::AUTO_PAUSE_UNTIL_FLAG);
        if ($autoPauseUntil > $now) {
            $this->autoDetectedReason = 'auto-pause active until ' . gmdate('Y-m-d H:i:s', $autoPauseUntil) . ' UTC';
            return $this->autoDetectedCache = true;
        }
        if ($autoPauseUntil > 0) {
            $this->flagManager->deleteFlag(self::AUTO_PAUSE_UNTIL_FLAG);
        }

        $current = $this->collectCurrentMtimes();
        if ($current === []) {
            $this->autoDetectedReason = 'no watched paths exist on disk';
            return $this->autoDetectedCache = false;
        }

        $lastSeen = $this->loadLastSeenMtimes();

        if ($lastSeen === []) {
            // First-ever observation — baseline, do NOT pause.
            $this->flagManager->saveFlag(self::DEPLOY_MTIMES_FLAG, $this->encodeMtimes($current));
            $this->autoDetectedReason = 'baseline established (first observation)';
            return $this->autoDetectedCache = false;
        }

        $newestChange = 0;
        $changedPath = null;
        foreach ($current as $path => $mtime) {
            $previous = (int)($lastSeen[$path] ?? 0);
            if ($mtime > $previous && $mtime > $newestChange) {
                $newestChange = $mtime;
                $changedPath = $path;
            }
        }

        if ($newestChange === 0) {
            $this->autoDetectedReason = 'no deploy-marker mtime change since last check';
            return $this->autoDetectedCache = false;
        }

        // Update the baseline immediately so the next check doesn't see the
        // same change again.
        $this->flagManager->saveFlag(self::DEPLOY_MTIMES_FLAG, $this->encodeMtimes($current));

        $windowEnd = $newestChange + ($minutes * 60);
        if ($windowEnd > $now) {
            // Real change AND still within window → set persistent pause flag.
            $this->flagManager->saveFlag(self::AUTO_PAUSE_UNTIL_FLAG, $windowEnd);
            $this->autoDetectedReason = sprintf(
                'deploy detected (%s mtime %s, suspending until %s UTC)',
                basename((string)$changedPath),
                gmdate('Y-m-d H:i:s', $newestChange),
                gmdate('Y-m-d H:i:s', $windowEnd)
            );
            return $this->autoDetectedCache = true;
        }

        $this->autoDetectedReason = 'change observed but window already elapsed';
        return $this->autoDetectedCache = false;
    }

    /**
     * @return array<string, int>
     */
    private function collectCurrentMtimes(): array
    {
        try {
            $root = $this->directoryList->getRoot();
        } catch (\Throwable $e) {
            return [];
        }
        $candidates = [
            $root . '/pub/static/deployed_version.txt',
            $root . '/generated/code',
            $root . '/generated/metadata',
        ];
        $out = [];
        foreach ($candidates as $path) {
            $stat = @stat($path);
            if ($stat !== false) {
                $out[$path] = (int)$stat['mtime'];
            }
        }
        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function loadLastSeenMtimes(): array
    {
        $raw = $this->flagManager->getFlagData(self::DEPLOY_MTIMES_FLAG);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $path => $mtime) {
            if (is_string($path) && (is_int($mtime) || ctype_digit((string)$mtime))) {
                $out[$path] = (int)$mtime;
            }
        }
        return $out;
    }

    /**
     * @param array<string, int> $mtimes
     */
    private function encodeMtimes(array $mtimes): string
    {
        return (string)json_encode($mtimes, JSON_UNESCAPED_SLASHES);
    }

    private function resolveWindowMinutes(): int
    {
        $minutes = (int)$this->scopeConfig->getValue(self::AUTO_PAUSE_CONFIG);
        if ($minutes <= 0) {
            return 0;
        }
        return $minutes > self::MAX_WINDOW_MINUTES ? self::MAX_WINDOW_MINUTES : $minutes;
    }

    /**
     * Pause capture for the given number of minutes. Returns the expiry epoch.
     */
    public function pause(int $minutes): int
    {
        $minutes = max(1, $minutes);
        $expiry = time() + $minutes * 60;
        $this->flagManager->saveFlag(self::PAUSE_FLAG, $expiry);
        return $expiry;
    }

    /**
     * Clear the explicit pause flag. (MaintenanceMode is unaffected.)
     */
    public function resume(): void
    {
        $this->flagManager->deleteFlag(self::PAUSE_FLAG);
    }

    /**
     * Wipe the auto-pause baseline + pause-until flags. Useful when
     * troubleshooting "capture seems silenced": forces the next check to
     * re-baseline rather than carrying over a stale state.
     */
    public function resetAutoDetect(): void
    {
        $this->flagManager->deleteFlag(self::AUTO_PAUSE_UNTIL_FLAG);
        $this->flagManager->deleteFlag(self::DEPLOY_MTIMES_FLAG);
    }

    /**
     * @return array{
     *   maintenance: bool,
     *   paused_until: int|null,
     *   auto_pause_until: int|null,
     *   auto_pause_reason: string,
     *   watched_mtimes: array<string, int>,
     *   last_seen_mtimes: array<string, int>,
     *   window_minutes: int,
     *   suspended: bool
     * }
     */
    public function status(): array
    {
        $maintenance = false;
        try {
            $maintenance = $this->maintenanceMode->isOn();
        } catch (\Throwable $e) {
            // fall through
        }
        $paused = 0;
        try {
            $paused = (int)$this->flagManager->getFlagData(self::PAUSE_FLAG);
        } catch (\Throwable $e) {
            // fall through
        }
        $watched = [];
        try {
            $watched = $this->collectCurrentMtimes();
        } catch (\Throwable $e) {
            // fall through
        }
        $lastSeen = [];
        try {
            $lastSeen = $this->loadLastSeenMtimes();
        } catch (\Throwable $e) {
            // fall through
        }
        // Run the auto-detect FIRST — it may write the AUTO_PAUSE_UNTIL flag
        // (when a new deploy is observed this very call), so reading the flag
        // beforehand would show a stale "no" while the reason field says
        // "deploy detected (suspending until ...)".
        $autoSuspended = false;
        try {
            $autoSuspended = $this->isInsideAutoDetectedDeployWindow();
        } catch (\Throwable $e) {
            // fall through
        }
        $autoPauseUntil = 0;
        try {
            $autoPauseUntil = (int)$this->flagManager->getFlagData(self::AUTO_PAUSE_UNTIL_FLAG);
        } catch (\Throwable $e) {
            // fall through
        }
        return [
            'maintenance'       => $maintenance,
            'paused_until'      => $paused > time() ? $paused : null,
            'auto_pause_until'  => $autoPauseUntil > time() ? $autoPauseUntil : null,
            'auto_pause_reason' => (string)$this->autoDetectedReason,
            'watched_mtimes'    => $watched,
            'last_seen_mtimes'  => $lastSeen,
            'window_minutes'    => $this->resolveWindowMinutes(),
            'suspended'         => $maintenance || ($paused > time()) || $autoSuspended,
        ];
    }

    /**
     * Default value exposed for the config field and admin help text.
     */
    public static function defaultAutoPauseMinutes(): int
    {
        return self::DEFAULT_WINDOW_MINUTES;
    }
}
