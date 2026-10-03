<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\FlagManager;

class DeploymentGuard
{
    public const PAUSE_FLAG = 'panth_errormonitor_capture_paused_until';

    public const AUTO_PAUSE_UNTIL_FLAG = 'panth_errormonitor_autopause_until';

    public const DEPLOY_MTIMES_FLAG = 'panth_errormonitor_deploy_mtimes';

    private const AUTO_PAUSE_CONFIG = 'panth_errormonitor/general/auto_pause_window_minutes';
    private const DEFAULT_WINDOW_MINUTES = 5;
    private const MAX_WINDOW_MINUTES = 120;

    private ?bool $autoDetectedCache = null;

    private ?string $autoDetectedReason = null;

    public function __construct(
        private readonly MaintenanceMode $maintenanceMode,
        private readonly FlagManager $flagManager,
        private readonly DirectoryList $directoryList,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

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

        $this->flagManager->saveFlag(self::DEPLOY_MTIMES_FLAG, $this->encodeMtimes($current));

        $windowEnd = $newestChange + ($minutes * 60);
        if ($windowEnd > $now) {
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

    public function pause(int $minutes): int
    {
        $minutes = max(1, $minutes);
        $expiry = time() + $minutes * 60;
        $this->flagManager->saveFlag(self::PAUSE_FLAG, $expiry);
        return $expiry;
    }

    public function resume(): void
    {
        $this->flagManager->deleteFlag(self::PAUSE_FLAG);
    }

    public function resetAutoDetect(): void
    {
        $this->flagManager->deleteFlag(self::AUTO_PAUSE_UNTIL_FLAG);
        $this->flagManager->deleteFlag(self::DEPLOY_MTIMES_FLAG);
    }

    public function status(): array
    {
        $maintenance = false;
        try {
            $maintenance = $this->maintenanceMode->isOn();
        } catch (\Throwable $e) {
        }
        $paused = 0;
        try {
            $paused = (int)$this->flagManager->getFlagData(self::PAUSE_FLAG);
        } catch (\Throwable $e) {
        }
        $watched = [];
        try {
            $watched = $this->collectCurrentMtimes();
        } catch (\Throwable $e) {
        }
        $lastSeen = [];
        try {
            $lastSeen = $this->loadLastSeenMtimes();
        } catch (\Throwable $e) {
        }

        $autoSuspended = false;
        try {
            $autoSuspended = $this->isInsideAutoDetectedDeployWindow();
        } catch (\Throwable $e) {
        }
        $autoPauseUntil = 0;
        try {
            $autoPauseUntil = (int)$this->flagManager->getFlagData(self::AUTO_PAUSE_UNTIL_FLAG);
        } catch (\Throwable $e) {
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

    public static function defaultAutoPauseMinutes(): int
    {
        return self::DEFAULT_WINDOW_MINUTES;
    }
}
