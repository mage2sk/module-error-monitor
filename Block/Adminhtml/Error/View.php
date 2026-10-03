<?php
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
    public const EVENTS_PER_PAGE = 50;
    private const DISTINCT_URL_LIMIT = 50;

    private const MAX_PAGES = 200;

    private ?ErrorGroup $group = null;
    private ?int $eventCount = null;

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

    public function getRecentEvents(): array
    {
        $group = $this->getGroup();
        if (!$group->getId()) {
            return [];
        }
        $collection = $this->eventCollectionFactory->create();
        $collection->addFieldToFilter('group_id', (int)$group->getId())
            ->setOrder('created_at', 'DESC')
            ->setPageSize(self::EVENTS_PER_PAGE)
            ->setCurPage($this->getCurrentPage());
        return $collection->getItems();
    }

    public function getCurrentPage(): int
    {
        $page = (int)$this->getRequest()->getParam('page', 1);
        if ($page < 1) {
            return 1;
        }
        $total = $this->getTotalPages();
        return $page > $total ? $total : $page;
    }

    public function getTotalEventCount(): int
    {
        if ($this->eventCount !== null) {
            return $this->eventCount;
        }
        $group = $this->getGroup();
        if (!$group->getId()) {
            return $this->eventCount = 0;
        }
        $conn = $this->eventResource->getConnection();
        $table = $this->eventResource->getMainTable();
        $count = (int)$conn->fetchOne(
            $conn->select()
                ->from($table, [new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
                ->where('group_id = ?', (int)$group->getId())
        );
        return $this->eventCount = $count;
    }

    public function getTotalPages(): int
    {
        $count = $this->getTotalEventCount();
        if ($count <= 0) {
            return 1;
        }
        $pages = (int)ceil($count / self::EVENTS_PER_PAGE);
        return $pages > self::MAX_PAGES ? self::MAX_PAGES : $pages;
    }

    public function getPaginationLinks(): array
    {
        $cur = $this->getCurrentPage();
        $last = $this->getTotalPages();
        if ($last <= 1) {
            return [];
        }
        $pages = [];
        $candidates = array_unique([
            1,
            $cur - 2, $cur - 1, $cur, $cur + 1, $cur + 2,
            $last,
        ]);
        sort($candidates);
        $prev = 0;
        foreach ($candidates as $p) {
            if ($p < 1 || $p > $last) {
                continue;
            }
            if ($prev > 0 && $p - $prev > 1) {
                $pages[] = ['page' => 0, 'url' => '', 'current' => false, 'label' => '...'];
            }
            $pages[] = [
                'page'    => $p,
                'url'     => $this->getPageUrl($p),
                'current' => $p === $cur,
                'label'   => (string)$p,
            ];
            $prev = $p;
        }
        return $pages;
    }

    public function getPrevPageUrl(): ?string
    {
        $cur = $this->getCurrentPage();
        return $cur > 1 ? $this->getPageUrl($cur - 1) : null;
    }

    public function getNextPageUrl(): ?string
    {
        $cur = $this->getCurrentPage();
        return $cur < $this->getTotalPages() ? $this->getPageUrl($cur + 1) : null;
    }

    private function getPageUrl(int $page): string
    {
        return $this->getUrl(
            'panth_errormonitor/error/view',
            ['group_id' => (int)$this->getGroup()->getId(), 'page' => $page]
        );
    }

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
