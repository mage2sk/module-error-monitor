<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Decides whether error capture is currently suspended — used to silence the
 * noise that happens during a deploy (visitors / bots hitting URLs while DI
 * is being recompiled, static assets are regenerating, indexers are running,
 * etc.). Two complementary signals:
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
 *
 * Both checks are wrapped — a failure in this service must NEVER break error
 * capture (it falls back to "not suspended").
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\FlagManager;

class DeploymentGuard
{
    /** Flag whose value is the unix timestamp at which the pause expires. */
    public const PAUSE_FLAG = 'panth_errormonitor_capture_paused_until';

    public function __construct(
        private readonly MaintenanceMode $maintenanceMode,
        private readonly FlagManager $flagManager
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
            if ($pausedUntil <= 0) {
                return false;
            }
            if ($pausedUntil > time()) {
                return true;
            }
            // Pause window elapsed — clear so a forgotten pause never lingers.
            $this->flagManager->deleteFlag(self::PAUSE_FLAG);
            return false;
        } catch (\Throwable $e) {
            // Never break capture if the check itself fails.
            return false;
        }
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
     * @return array{maintenance: bool, paused_until: int|null, suspended: bool}
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
        return [
            'maintenance'  => $maintenance,
            'paused_until' => $paused > time() ? $paused : null,
            'suspended'    => $maintenance || ($paused > time()),
        ];
    }
}
