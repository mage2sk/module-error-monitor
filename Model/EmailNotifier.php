<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model;

use Magento\Framework\App\Area;
use Magento\Framework\DataObject;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\Summary\DigestHolder;
use Psr\Log\LoggerInterface;

class EmailNotifier
{
    private const TEMPLATE_ID = 'panth_errormonitor_alert_template';

    private readonly DigestHolder $digestHolder;

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        ?DigestHolder $digestHolder = null
    ) {
        $this->digestHolder = $digestHolder ?? new DigestHolder();
    }

    public function sendDigest(array $digest): bool
    {
        $rows = $digest['rows'] ?? [];
        if ($rows === []) {
            return false;
        }
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row['group_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $this->digestHolder->set($digest);
        try {
            return $this->dispatch(
                $ids,
                (string)($digest['timezone'] ?? 'UTC'),
                max(count($ids), (int)($digest['total_groups'] ?? 0))
            );
        } finally {
            $this->digestHolder->clear();
        }
    }

    public function send(array $groups): bool
    {
        $recipients = $this->config->getEmailRecipients();
        if ($groups === [] || $recipients === []) {
            return false;
        }

        $ids = [];
        foreach ($groups as $group) {
            $id = (int)$group->getData('group_id');
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $this->dispatch($ids, '', count($ids));
    }

    private function dispatch(array $ids, string $timezone, int $count): bool
    {
        $recipients = $this->config->getEmailRecipients();
        if ($ids === [] || $recipients === []) {
            return false;
        }

        $subject = sprintf(
            '[%s] Error Monitor: %d error group%s in the last 24 hours',
            $this->siteName(),
            $count,
            $count === 1 ? '' : 's'
        );

        $storeId = $this->resolveStoreId();

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $this->transportBuilder
                ->setTemplateIdentifier(self::TEMPLATE_ID)
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars([
                    'subject'     => $subject,
                    'site_name'   => $this->siteName(),
                    'error_count' => $count,
                    'group_ids'   => implode(',', $ids),
                    'timezone'    => $timezone !== '' ? $timezone : 'UTC',
                ])
                ->setFromByScope($this->config->getEmailSender());

            foreach ($recipients as $recipient) {
                $this->transportBuilder->addTo($recipient);
            }

            $this->transportBuilder->getTransport()->sendMessage();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthErrorMonitor] email send failed: ' . $e->getMessage());
            return false;
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    private function resolveStoreId(): int
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            if ($store !== null) {
                return (int)$store->getId();
            }
            foreach ($this->storeManager->getStores() as $s) {
                return (int)$s->getId();
            }
        } catch (\Throwable $e) {
        }
        return (int)Store::DEFAULT_STORE_ID;
    }

    private function siteName(): string
    {
        try {
            return (string)($this->storeManager->getDefaultStoreView()?->getName() ?: 'Magento');
        } catch (\Throwable $e) {
            return 'Magento';
        }
    }
}
