<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * UI grid data source for the admin error listing. Follows the standard
 * SearchResult pattern (matching mainTable + resourceModel are wired in
 * etc/di.xml and registered with the UiComponent CollectionFactory).
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\Grid;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Psr\Log\LoggerInterface;

class Collection extends SearchResult implements SearchResultInterface
{
    /**
     * @var string
     */
    protected $_idFieldName = 'group_id';

    /**
     * @var AggregationInterface|null
     */
    protected $aggregations;

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        string $mainTable = ErrorGroupResource::TABLE_NAME,
        string $resourceModel = ErrorGroupResource::class,
        ?string $identifierName = 'group_id',
        ?string $connectionName = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    protected function _initSelect(): static
    {
        parent::_initSelect();
        $this->addFilterToMap('group_id', 'main_table.group_id');
        return $this;
    }

    protected function _afterLoad(): static
    {
        parent::_afterLoad();
        foreach ($this->_items as $item) {
            if ($item->getData('group_id')) {
                $item->setId($item->getData('group_id'));
            }
        }
        return $this;
    }

    public function getAggregations(): AggregationInterface
    {
        return $this->aggregations;
    }

    public function setAggregations($aggregations): static
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    public function getSearchCriteria(): ?SearchCriteriaInterface
    {
        return null;
    }

    public function setSearchCriteria(SearchCriteriaInterface $searchCriteria): static
    {
        return $this;
    }

    public function getTotalCount(): int
    {
        return $this->getSize();
    }

    public function setTotalCount($totalCount): static
    {
        return $this;
    }

    public function setItems(?array $items = null): static
    {
        return $this;
    }
}
