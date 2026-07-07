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
use Psr\Log\LoggerInterface;

class EmailNotifier
{
    private const TEMPLATE_ID = 'panth_errormonitor_alert_template';

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
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
        if ($ids === []) {
            return false;
        }

        $count = count($ids);
        $subject = sprintf(
            '[%s] Error Monitor: %d error group%s',
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
