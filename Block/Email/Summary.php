<?php
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
            ? mb_substr($value, 0, $max, 'UTF-8') . '...'
            : $value;
    }
}
