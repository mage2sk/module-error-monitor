<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Sends the error alert email. The HTML body (the per-error cards) is rendered
 * by Block\Email\Summary via the {{block}} directive in the template, so it is
 * emitted as trusted, un-escaped markup. This class only computes the subject
 * and passes scalar template vars — notably the selected group ids as a CSV.
 *
 * Rendered in the FRONTEND area with a real store view so the shared
 * design/email header & footer templates resolve (they cannot in adminhtml).
 */
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

    /**
     * Send one email covering the supplied error groups.
     *
     * @param DataObject[] $groups
     */
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
        // Emulate the frontend store so getTransport() / sendMessage() work
        // identically from CLI, cron and web — a console command otherwise has
        // no area context and the transport fails to resolve.
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

    /**
     * A real (frontend) store view id so email header/footer templates resolve.
     */
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
            // fall through
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
