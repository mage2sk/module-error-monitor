<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Builds and sends error-alert emails. All dynamic content is HTML-escaped
 * here (error messages can contain attacker-influenced text) and passed to
 * the template as a single pre-rendered, raw block — the template itself
 * never interpolates raw error fields.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Area;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
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
        private readonly BackendUrl $backendUrl,
        private readonly Escaper $escaper,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send a single digest email covering every supplied group.
     *
     * @param array<int, \Magento\Framework\DataObject> $groups
     */
    public function sendDigest(array $groups): bool
    {
        if ($groups === []) {
            return false;
        }
        $count = count($groups);
        $subject = sprintf('[%s] %d error%s need attention', $this->siteName(), $count, $count === 1 ? '' : 's');
        $body = '';
        foreach ($groups as $group) {
            $body .= $this->renderGroup($group);
        }
        return $this->dispatch($subject, $body, $count);
    }

    /**
     * Send one email about a single group.
     */
    public function sendSingle(\Magento\Framework\DataObject $group): bool
    {
        $subject = sprintf(
            '[%s] %s: %s',
            $this->siteName(),
            ucfirst((string)$group->getData('severity')),
            $this->shorten((string)$group->getData('message'), 80)
        );
        return $this->dispatch($subject, $this->renderGroup($group), 1);
    }

    private function dispatch(string $subject, string $contentHtml, int $count): bool
    {
        $recipients = $this->config->getEmailRecipients();
        if ($recipients === []) {
            return false;
        }
        try {
            $this->transportBuilder
                ->setTemplateIdentifier(self::TEMPLATE_ID)
                ->setTemplateOptions([
                    // Frontend area + a real store view so the shared
                    // design/email header & footer templates resolve. (In the
                    // adminhtml area those config-path includes can't be found.)
                    'area' => Area::AREA_FRONTEND,
                    'store' => $this->resolveStoreId(),
                ])
                ->setTemplateVars([
                    'subject'      => $subject,
                    'site_name'    => $this->siteName(),
                    'error_count'  => $count,
                    'content_html' => $contentHtml,
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
        }
    }

    /**
     * Render one escaped HTML card for a group.
     */
    private function renderGroup(\Magento\Framework\DataObject $group): string
    {
        $esc = fn ($v) => $this->escaper->escapeHtml((string)$v);
        $severity = strtolower((string)$group->getData('severity'));
        $color = match ($severity) {
            'emergency', 'alert', 'critical' => '#b91c1c',
            'error' => '#c2410c',
            'warning' => '#b45309',
            default => '#475569',
        };
        $viewUrl = $this->backendUrl->getUrl(
            'panth_errormonitor/error/view',
            ['group_id' => (int)$group->getData('group_id')]
        );
        $fileLine = trim((string)$group->getData('file') . ((int)$group->getData('line') ? ':' . (int)$group->getData('line') : ''));

        return '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px;border:1px solid #e2e8f0;border-radius:8px;">'
            . '<tr><td style="padding:14px 18px;font-family:Arial,Helvetica,sans-serif;">'
            . '<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:' . $color . ';color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.5px;">'
            . $esc(strtoupper($severity)) . '</span> '
            . '<span style="color:#64748b;font-size:12px;">' . $esc(strtoupper((string)$group->getData('source'))) . '</span>'
            . '<p style="margin:10px 0 4px;font-size:15px;color:#0f172a;font-weight:bold;">' . $esc($group->getData('error_type')) . '</p>'
            . '<p style="margin:0 0 8px;font-size:14px;color:#334155;line-height:1.5;">' . $esc($this->shorten((string)$group->getData('message'), 400)) . '</p>'
            . ($fileLine !== '' ? '<p style="margin:0 0 4px;font-size:12px;color:#64748b;font-family:monospace;">' . $esc($fileLine) . '</p>' : '')
            . '<p style="margin:0 0 10px;font-size:12px;color:#64748b;">'
            . 'Occurrences: <strong>' . (int)$group->getData('occurrence_count') . '</strong>'
            . ' &nbsp;|&nbsp; Last seen: ' . $esc((string)$group->getData('last_seen_at')) . ' UTC</p>'
            . '<a href="' . $esc($viewUrl) . '" style="display:inline-block;padding:8px 16px;background:#0f172a;color:#fff;text-decoration:none;border-radius:6px;font-size:13px;">View details</a>'
            . '</td></tr></table>';
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
            return (string)$this->storeManager->getDefaultStoreView()?->getName() ?: 'Magento';
        } catch (\Throwable $e) {
            return 'Magento';
        }
    }

    private function shorten(string $value, int $max): string
    {
        return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') . '…' : $value;
    }
}
