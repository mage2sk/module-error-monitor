<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Cron;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\FlagManager;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\EmailNotifier;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use Psr\Log\LoggerInterface;

class DispatchNotifications
{
    private const SUMMARY_FLAG_PREFIX = 'panth_errormonitor_summary_';

    private ?DigestBuilder $digestBuilder;

    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $collectionFactory,
        private readonly ErrorGroupResource $groupResource,
        private readonly EmailNotifier $notifier,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger,
        ?DigestBuilder $digestBuilder = null
    ) {
        $this->digestBuilder = $digestBuilder;
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

        $builder = $this->builder();
        $digest = $builder->build();
        $ids = $builder->groupIds($digest);
        if ($ids === []) {
            return;
        }

        if ($this->notifier->sendDigest($digest)) {
            $this->flagManager->saveFlag($flagCode, 1);
            $this->markEmailed($ids, $today);
        }
    }

    private function builder(): DigestBuilder
    {
        if ($this->digestBuilder === null) {
            $this->digestBuilder = ObjectManager::getInstance()->get(DigestBuilder::class);
        }
        return $this->digestBuilder;
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
}
