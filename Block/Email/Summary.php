<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Block\Email;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use Panth\ErrorMonitor\Model\Summary\DigestHolder;

class Summary extends Template
{
    private readonly DigestHolder $digestHolder;

    private ?DigestBuilder $digestBuilder;

    private ?array $digest = null;

    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        private readonly BackendUrl $backendUrl,
        array $data = [],
        ?DigestHolder $digestHolder = null,
        ?DigestBuilder $digestBuilder = null
    ) {
        parent::__construct($context, $data);
        $this->digestHolder = $digestHolder ?? new DigestHolder();
        $this->digestBuilder = $digestBuilder;
    }

    public function getDigest(): array
    {
        if ($this->digest !== null) {
            return $this->digest;
        }
        $digest = $this->digestHolder->get();
        if ($digest === null) {
            $ids = $this->requestedIds();
            $digest = $ids === [] ? ['rows' => []] : $this->builder()->build(null, $ids);
        }
        $this->digest = $digest;
        return $digest;
    }

    public function getRows(): array
    {
        return $this->getDigest()['rows'] ?? [];
    }

    public function getErrorGroups(): array
    {
        $ids = $this->requestedIds();
        if ($ids === []) {
            return [];
        }
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('group_id', ['in' => $ids])
            ->setOrder('severity', 'DESC')
            ->setOrder('occurrence_count', 'DESC');
        return $collection->getItems();
    }

    public function getViewUrl($group): string
    {
        $id = is_array($group) ? (int)($group['group_id'] ?? 0) : (int)$group->getData('group_id');
        return $this->backendUrl->getUrl(
            'panth_errormonitor/error/view',
            ['group_id' => $id, '_nosecret' => true]
        );
    }

    public function getFooterLines(): array
    {
        $digest = $this->getDigest();
        $lines = [];
        $timezone = (string)($digest['timezone'] ?? '');
        if (!empty($digest['mixed_timezones'])) {
            $lines[] = (string)__(
                'Times are shown in the timezone of the store view where each error was last seen; errors without a store view use %1. Values are stored in UTC.',
                $timezone !== '' ? $timezone : 'UTC'
            );
        } elseif ($timezone !== '') {
            $lines[] = (string)__('Times are shown in the store timezone (%1). Values are stored in UTC.', $timezone);
        }
        $hidden = (int)($digest['hidden_total'] ?? 0);
        if ($hidden > 0) {
            $lines[] = (string)__(
                '%1 group(s) with events in the last 24 hours were hidden: %2 by ignore rules, %3 by the bot filter, %4 GraphQL client errors.',
                $hidden,
                (int)($digest['hidden_ignored'] ?? 0),
                (int)($digest['hidden_bot'] ?? 0),
                (int)($digest['hidden_graphql'] ?? 0)
            );
        } else {
            $lines[] = (string)__('No groups were hidden by ignore rules or the bot filter.');
        }
        $resolved = (int)($digest['resolved_active'] ?? 0);
        if ($resolved > 0) {
            $lines[] = (string)__(
                '%1 group(s) had events in the last 24 hours but are already marked as resolved and are not listed.',
                $resolved
            );
        }
        $notShown = (int)($digest['not_shown'] ?? 0);
        if ($notShown > 0) {
            $lines[] = (string)__('%1 more group(s) are not listed because of the per-email limit.', $notShown);
        }
        return $lines;
    }

    public function formatCounts(array $row): string
    {
        return (string)__(
            '%1 in last 24 h (%2 total)',
            (string)($row['count_label'] ?? (int)($row['count_24h'] ?? 0)),
            (int)($row['occurrence_count'] ?? 0)
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

    private function requestedIds(): array
    {
        $raw = (string)$this->getData('group_ids');
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    private function builder(): DigestBuilder
    {
        if ($this->digestBuilder === null) {
            $this->digestBuilder = ObjectManager::getInstance()->get(DigestBuilder::class);
        }
        return $this->digestBuilder;
    }
}
