<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Model\ResourceModel\ErrorGroup\Grid;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\Grid\Collection;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
{
    private function collection(): Collection
    {
        return (new \ReflectionClass(Collection::class))->newInstanceWithoutConstructor();
    }

    public function testAggregationsRoundTrip(): void
    {
        $collection = $this->collection();
        $aggregations = $this->createStub(AggregationInterface::class);
        $this->assertSame($collection, $collection->setAggregations($aggregations));
        $this->assertSame($aggregations, $collection->getAggregations());
    }

    public function testSearchCriteriaAndSettersAreNoOps(): void
    {
        $collection = $this->collection();
        $this->assertNull($collection->getSearchCriteria());
        $this->assertSame($collection, $collection->setSearchCriteria($this->createStub(SearchCriteriaInterface::class)));
        $this->assertNull($collection->getSearchCriteria());
        $this->assertSame($collection, $collection->setTotalCount(99));
        $this->assertSame($collection, $collection->setItems([new DataObject()]));
    }

    public function testAfterLoadCopiesGroupIdIntoItemId(): void
    {
        $collection = $this->collection();
        $withId = new DataObject(['group_id' => 12]);
        $withoutId = new DataObject(['message' => 'x']);
        $eventManager = $this->createStub(ManagerInterface::class);
        \Closure::bind(function () use ($withId, $withoutId, $eventManager): void {
            $this->_items = [$withId, $withoutId];
            $this->_eventManager = $eventManager;
        }, $collection, Collection::class)();

        $afterLoad = new \ReflectionMethod($collection, '_afterLoad');
        $this->assertSame($collection, $afterLoad->invoke($collection));
        $this->assertSame(12, $withId->getData('id'));
        $this->assertNull($withoutId->getData('id'));
    }

    public function testGetAggregationsBeforeSetReturnsNull(): void
    {
        $this->assertNull($this->collection()->getAggregations());
    }
}
