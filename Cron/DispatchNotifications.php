<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Cron;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\FlagManager;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\EmailNotifier;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Psr\Log\LoggerInterface;

class DispatchNotifications
{
    private const SUMMARY_FLAG_PREFIX = 'panth_errormonitor_summary_';

    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $collectionFactory,
        private readonly ErrorGroupResource $groupResource,
        private readonly EmailNotifier $notifier,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            if (!$this->config->isEmailEnabled() || $this->config->getEmailRecipients() === []) {
                return;
            }
            $this->runDailySummary();
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthErrorMonitor] dispatch failed: ' . $e->getMessage());
        }
    }

    private function runDailySummary(): void
    {
        if ((int)gmdate('G') < $this->config->getEmailSendHour()) {
            return;
        }
        $today = gmdate('Y-m-d');
        $flagCode = self::SUMMARY_FLAG_PREFIX . $today;
        if ($this->flagManager->getFlagData($flagCode)) {
            return;
        }

        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ErrorGroup::STATUS_NEW)
            ->addFieldToFilter('severity', ['in' => $this->severitiesAtOrAbove($this->config->getEmailMinSeverity())])
            ->addFieldToFilter('last_seen_at', ['gteq' => $since])
            ->setOrder('severity', 'DESC')
            ->setOrder('occurrence_count', 'DESC')
            ->setPageSize($this->config->getEmailMaxPerRun())
            ->setCurPage(1);

        $groups = array_values($collection->getItems());
        if ($groups === []) {
            $this->flagManager->saveFlag($flagCode, 1);
            return;
        }

        if ($this->notifier->send($groups)) {
            $this->flagManager->saveFlag($flagCode, 1);
            $this->markEmailed($this->ids($groups), $today);
        }
    }

    private function ids(array $groups): array
    {
        return array_values(array_filter(array_map(static fn ($g) => (int)$g->getData('group_id'), $groups)));
    }

    private function markEmailed(array $groupIds, string $today): void
    {
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
