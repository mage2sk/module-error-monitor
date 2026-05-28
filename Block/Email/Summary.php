<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Renders the error cards inside the alert email. Used via the {{block}}
 * directive in view/frontend/email/error_alert.html so its output is treated
 * as trusted HTML (NOT escaped) — the previous approach of passing pre-built
 * HTML through {{var}} was double-escaped by the email filter and showed up
 * as literal markup in the inbox.
 *
 * The cron passes the selected group ids as a scalar CSV ("12,15,18"); this
 * block loads those rows and the template escapes every dynamic field.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Block\Email;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;

class Summary extends Template
{
    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        private readonly BackendUrl $backendUrl,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Error groups to render, loaded from the CSV id list set by the cron.
     *
     * @return \Magento\Framework\DataObject[]
     */
    public function getErrorGroups(): array
    {
        $raw = (string)$this->getData('group_ids');
        $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
        if ($ids === []) {
            return [];
        }
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('group_id', ['in' => $ids])
            ->setOrder('severity', 'DESC')
            ->setOrder('occurrence_count', 'DESC');
        return $collection->getItems();
    }

    public function getViewUrl(\Magento\Framework\DataObject $group): string
    {
        return $this->backendUrl->getUrl(
            'panth_errormonitor/error/view',
            ['group_id' => (int)$group->getData('group_id')]
        );
    }

    public function severityColor(string $severity): string
    {
        return match (strtolower($severity)) {
            'emergency', 'alert', 'critical' => '#b91c1c',
            'error' => '#c2410c',
            'warning' => '#b45309',
            default => '#475569',
        };
    }

    public function shorten(string $value, int $max): string
    {
        return mb_strlen($value, 'UTF-8') > $max
            ? mb_substr($value, 0, $max, 'UTF-8') . '…'
            : $value;
    }
}
