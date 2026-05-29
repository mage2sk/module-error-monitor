<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Backing block for the error detail page: exposes the group aggregate and
 * its most recent occurrences. All output is escaped in the template.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Block\Adminhtml\Error;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\DataObject;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ErrorGroupFactory;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent\CollectionFactory as EventCollectionFactory;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;

class View extends Template
{
    private const RECENT_EVENT_LIMIT = 50;
    private const DISTINCT_URL_LIMIT = 50;

    private ?ErrorGroup $group = null;

    public function __construct(
        Context $context,
        private readonly ErrorGroupFactory $groupFactory,
        private readonly ErrorGroupResource $groupResource,
        private readonly EventCollectionFactory $eventCollectionFactory,
        private readonly ErrorEventResource $eventResource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getGroup(): ErrorGroup
    {
        if ($this->group === null) {
            $id = (int)$this->getRequest()->getParam('group_id');
            $group = $this->groupFactory->create();
            if ($id) {
                $this->groupResource->load($group, $id);
            }
            $this->group = $group;
        }
        return $this->group;
    }

    /**
     * @return DataObject[]
     */
    public function getRecentEvents(): array
    {
        $group = $this->getGroup();
        if (!$group->getId()) {
            return [];
        }
        $collection = $this->eventCollectionFactory->create();
        $collection->addFieldToFilter('group_id', (int)$group->getId())
            ->setOrder('created_at', 'DESC')
            ->setPageSize(self::RECENT_EVENT_LIMIT)
            ->setCurPage(1);
        return $collection->getItems();
    }

    /**
     * Every distinct URL where this error has been recorded, with the number
     * of occurrences per URL, ordered by frequency descending. Capped to the
     * top DISTINCT_URL_LIMIT so a pathological event count can't blow up the
     * admin page render.
     *
     * @return array<int, array{url:string, occurrences:int}>
     */
    public function getDistinctUrls(): array
    {
        $group = $this->getGroup();
        if (!$group->getId()) {
            return [];
        }
        $conn = $this->eventResource->getConnection();
        $table = $this->eventResource->getMainTable();
        $select = $conn->select()
            ->from($table, ['url' => 'url', 'occurrences' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
            ->where('group_id = ?', (int)$group->getId())
            ->where('url IS NOT NULL')
            ->where("url <> ''")
            ->group('url')
            ->order('occurrences DESC')
            ->limit(self::DISTINCT_URL_LIMIT);
        $rows = $conn->fetchAll($select);
        return array_map(static fn ($r) => [
            'url' => (string)$r['url'],
            'occurrences' => (int)$r['occurrences'],
        ], $rows);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('panth_errormonitor/error/index');
    }

    public function getResolveUrl(): string
    {
        return $this->getUrl('panth_errormonitor/error/resolve', ['group_id' => (int)$this->getGroup()->getId()]);
    }

    public function getIgnoreUrl(): string
    {
        return $this->getUrl('panth_errormonitor/error/ignore', ['group_id' => (int)$this->getGroup()->getId()]);
    }

    public function statusLabel(int $status): string
    {
        return match ($status) {
            ErrorGroup::STATUS_RESOLVED => (string)__('Resolved'),
            ErrorGroup::STATUS_IGNORED => (string)__('Ignored'),
            default => (string)__('New'),
        };
    }

    /**
     * Pretty-print the stored JSON context.
     */
    public function formatContext(?string $context): string
    {
        if ($context === null || $context === '') {
            return '';
        }
        $decoded = json_decode($context, true);
        if (!is_array($decoded)) {
            return $context;
        }
        return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
