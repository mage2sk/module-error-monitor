<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Decides whether error capture is currently suspended — used to silence the
 * noise that happens during a deploy (visitors / bots hitting URLs while DI
 * is being recompiled, static assets are regenerating, indexers are running,
 * etc.). Three complementary signals:
 *
 *   1. Magento's own MaintenanceMode — the canonical "we're deploying" flag.
 *      No extra admin work needed: a deploy that runs `maintenance:enable`
 *      automatically pauses capture for its duration.
 *   2. An explicit, auto-expiring pause flag set by
 *      `bin/magento panth:errormonitor:pause [--minutes=N]` for admins who
 *      deploy without maintenance mode, or who want a hard kill-switch.
 *      The expiry timestamp is stored in the flag; once past, the flag is
 *      lazily cleared on the next check so a forgotten pause never silences
 *      the module forever.
 *   3. **Filesystem-mtime auto-detect** (new in 1.5.1) — most deploys forget
 *      maintenance mode. We check the mtime of paths that ONLY change on a
 *      real deploy (`pub/static/deployed_version.txt` written by
 *      `setup:static-content:deploy`, the `generated/code` and
 *      `generated/metadata` directories written by `setup:di:compile`) and
 *      suspend capture for `general/auto_pause_window_minutes` after the
 *      most recent of those touches. Deliberately NOT watching `var/cache`
 *      because that gets cleared by every admin config save — too noisy.
 *
 * Every check is wrapped — a failure in this service must NEVER break error
 * capture (it falls back to "not suspended"). The mtime check stat()'s each
 * path at most once per request via a static cache.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\FlagManager;

class DeploymentGuard
{
    /** Flag whose value is the unix timestamp at which the pause expires. */
    public const PAUSE_FLAG = 'panth_errormonitor_capture_paused_until';

    private const AUTO_PAUSE_CONFIG = 'panth_errormonitor/general/auto_pause_window_minutes';
    private const DEFAULT_WINDOW_MINUTES = 15;
    private const MAX_WINDOW_MINUTES = 120;

    /** Per-request cache for the mtime check (set once, cleared on PHP exit). */
    private ?bool $autoDetectedCache = null;

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
                // Pause window elapsed — clear so a forgotten pause never lingers.
                $this->flagManager->deleteFlag(self::PAUSE_FLAG);
            }
            return $this->isInsideAutoDetectedDeployWindow();
        } catch (\Throwable $e) {
            // Never break capture if the check itself fails.
            return false;
        }
    }

    /**
     * Filesystem-mtime auto-detect — see class docblock.
     */
    private function isInsideAutoDetectedDeployWindow(): bool
    {
        if ($this->autoDetectedCache !== null) {
            return $this->autoDetectedCache;
        }
        $minutes = (int)$this->scopeConfig->getValue(self::AUTO_PAUSE_CONFIG);
        if ($minutes <= 0) {
            return $this->autoDetectedCache = false;
        }
        if ($minutes > self::MAX_WINDOW_MINUTES) {
            $minutes = self::MAX_WINDOW_MINUTES;
        }
        $cutoff = time() - ($minutes * 60);

        try {
            $root = $this->directoryList->getRoot();
            $candidates = [
                $root . '/pub/static/deployed_version.txt',
                $root . '/generated/code',
                $root . '/generated/metadata',
            ];
        } catch (\Throwable $e) {
            return $this->autoDetectedCache = false;
        }

        foreach ($candidates as $path) {
            $stat = @stat($path);
            if ($stat !== false && (int)$stat['mtime'] > $cutoff) {
                return $this->autoDetectedCache = true;
            }
        }
        return $this->autoDetectedCache = false;
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
     * @return array{maintenance: bool, paused_until: int|null, auto_detected: bool, suspended: bool}
     */
    public function status(): array
    {
        try {
            $paused = (int)$this->flagManager->getFlagData(self::PAUSE_FLAG);
        } catch (\Throwable $e) {
            $paused = 0;
        }
        try {
            $maintenance = $this->maintenanceMode->isOn();
        } catch (\Throwable $e) {
            $maintenance = false;
        }
        $autoDetected = false;
        try {
            $autoDetected = $this->isInsideAutoDetectedDeployWindow();
        } catch (\Throwable $e) {
            // status() must never throw — leave as false.
        }
        return [
            'maintenance'   => $maintenance,
            'paused_until'  => $paused > time() ? $paused : null,
            'auto_detected' => $autoDetected,
            'suspended'     => $maintenance || ($paused > time()) || $autoDetected,
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
