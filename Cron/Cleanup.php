<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Retention enforcement so the tables can never grow without bound:
 *   - prune individual event rows older than the configured window;
 *   - delete resolved groups not seen for the configured window (their
 *     events cascade away via the FK).
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Cron;

use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Psr\Log\LoggerInterface;

class Cleanup
{
    public function __construct(
        private readonly Config $config,
        private readonly ErrorEventResource $eventResource,
        private readonly ErrorGroupResource $groupResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $this->run();
    }

    /**
     * @return array{events:int, groups:int}
     */
    public function run(): array
    {
        $deletedEvents = 0;
        $deletedGroups = 0;
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
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthErrorMonitor] cleanup failed: ' . $e->getMessage());
        }
        return ['events' => $deletedEvents, 'groups' => $deletedGroups];
    }
}
