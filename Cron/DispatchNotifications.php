<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Decouples capture from notification. Errors are written instantly by the
 * handler/beacon; THIS cron is the only thing that ever sends mail. It selects
 * new (or recurred) error groups that are due an alert and either bundles them
 * into one digest or sends them individually — then stamps each group with
 * today's date so it can't be emailed again until tomorrow.
 *
 * Three layers stop the inbox flooding:
 *   1. Per-group daily dedupe (last_emailed_date).
 *   2. Severity threshold (email/min_severity).
 *   3. Hard per-run cap (email/max_per_run).
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Cron;

use Magento\Framework\DB\Sql\Expression;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\Config\Source\EmailMode;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\EmailNotifier;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Psr\Log\LoggerInterface;

class DispatchNotifications
{
    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $collectionFactory,
        private readonly ErrorGroupResource $groupResource,
        private readonly EmailNotifier $notifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            if (!$this->config->isEmailEnabled()) {
                return;
            }
            if ($this->config->getEmailRecipients() === []) {
                return;
            }

            $today = gmdate('Y-m-d');
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('status', ErrorGroup::STATUS_NEW)
                ->addFieldToFilter('severity', ['in' => $this->severitiesAtOrAbove($this->config->getEmailMinSeverity())])
                ->addFieldToFilter(
                    'last_emailed_date',
                    [['null' => true], ['lt' => $today]]
                )
                ->setOrder('last_seen_at', 'DESC')
                ->setPageSize($this->config->getEmailMaxPerRun())
                ->setCurPage(1);

            $groups = $collection->getItems();
            if ($groups === []) {
                return;
            }

            $sent = false;
            if ($this->config->getEmailMode() === EmailMode::MODE_INDIVIDUAL) {
                foreach ($groups as $group) {
                    $sent = $this->notifier->sendSingle($group) || $sent;
                }
            } else {
                $sent = $this->notifier->sendDigest(array_values($groups));
            }

            if ($sent) {
                $this->markEmailed(array_map(static fn ($g) => (int)$g->getData('group_id'), $groups), $today);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthErrorMonitor] dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * @param int[] $groupIds
     */
    private function markEmailed(array $groupIds, string $today): void
    {
        $groupIds = array_values(array_filter($groupIds));
        if ($groupIds === []) {
            return;
        }
        $conn = $this->groupResource->getConnection();
        $conn->update(
            $this->groupResource->getMainTable(),
            [
                'last_emailed_date' => $today,
                'emailed_count' => new Expression('emailed_count + 1'),
            ],
            ['group_id IN (?)' => $groupIds]
        );
    }

    /**
     * @return string[]
     */
    private function severitiesAtOrAbove(string $minSeverity): array
    {
        $min = Severity::rank($minSeverity);
        $out = [];
        foreach (Severity::RANKS as $name => $rank) {
            if ($rank >= $min) {
                $out[] = $name;
            }
        }
        return $out ?: ['error', 'critical', 'alert', 'emergency'];
    }
}
