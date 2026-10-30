<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Cron;

use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Service\RateLimiter;
use Psr\Log\LoggerInterface;

class Cleanup
{
    public const HOURLY_RETENTION_DAYS = 35;

    public function __construct(
        private readonly Config $config,
        private readonly ErrorEventResource $eventResource,
        private readonly ErrorGroupResource $groupResource,
        private readonly LoggerInterface $logger,
        private readonly ?RateLimiter $rateLimiter = null
    ) {
    }

    public function execute(): void
    {
        $this->run();
        $this->pruneCounters();
    }

    public function run(): array
    {
        $deletedEvents = 0;
        $deletedGroups = 0;
        $deletedUnresolved = 0;
        try {
            $eventCutoff = gmdate('Y-m-d H:i:s', time() - $this->config->getEventRetentionDays() * 86400);
            $conn = $this->eventResource->getConnection();
            $deletedEvents = (int)$conn->delete(
                $this->eventResource->getMainTable(),
                ['created_at < ?' => $eventCutoff]
            );

            $groupCutoff = gmdate('Y-m-d H:i:s', time() - $this->config->getResolvedGroupRetentionDays() * 86400);
            $groupConn = $this->groupResource->getConnection();
            $deletedGroups = (int)$groupConn->delete(
                $this->groupResource->getMainTable(),
                [
                    'status = ?' => ErrorGroup::STATUS_RESOLVED,
                    'last_seen_at < ?' => $groupCutoff,
                ]
            );

            $unresolvedDays = $this->config->getUnresolvedGroupRetentionDays();
            if ($unresolvedDays > 0) {
                $deletedUnresolved = (int)$groupConn->delete(
                    $this->groupResource->getMainTable(),
                    [
                        'status = ?' => ErrorGroup::STATUS_NEW,
                        'last_seen_at < ?' => gmdate('Y-m-d H:i:s', time() - $unresolvedDays * 86400),
                    ]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthErrorMonitor] cleanup failed: ' . $e->getMessage());
        }
        return ['events' => $deletedEvents, 'groups' => $deletedGroups, 'unresolved_groups' => $deletedUnresolved];
    }

    public function pruneCounters(?int $now = null): array
    {
        $now ??= time();
        $result = ['hourly_counters' => 0, 'rate_limit_rows' => 0];
        try {
            $table = $this->groupResource->getHourlyTable();
            $result['hourly_counters'] = (int)$this->groupResource->getConnection()->delete(
                $table !== '' ? $table : ErrorGroupResource::HOURLY_TABLE,
                ['hour_start < ?' => gmdate('Y-m-d H:00:00', $now - self::HOURLY_RETENTION_DAYS * 86400)]
            );
            if ($this->rateLimiter !== null) {
                $result['rate_limit_rows'] = $this->rateLimiter->purgeExpired($now);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthErrorMonitor] counter cleanup failed: ' . $e->getMessage());
        }
        return $result;
    }
}
