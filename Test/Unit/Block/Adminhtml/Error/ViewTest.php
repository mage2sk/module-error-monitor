<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Block\Adminhtml\Error;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\UrlInterface;
use Panth\ErrorMonitor\Block\Adminhtml\Error\View;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ErrorGroupFactory;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent\Collection as EventCollection;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent\CollectionFactory as EventCollectionFactory;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    private array $params = [];

    private ?int $groupId = 7;

    private int $eventCount = 0;

    private int $countQueries = 0;

    private int $factoryCreates = 0;

    private array $loads = [];

    private array $collectionCalls = [];

    private array $selectCalls = [];

    private array $urlRows = [];

    private function block(): View
    {
        $group = $this->createStub(ErrorGroup::class);
        $group->method('getId')->willReturnCallback(fn () => $this->groupId);
        $groupFactory = $this->createStub(ErrorGroupFactory::class);
        $groupFactory->method('create')->willReturnCallback(function () use ($group) {
            $this->factoryCreates++;
            return $group;
        });
        $groupResource = $this->createStub(ErrorGroupResource::class);
        $groupResource->method('load')->willReturnCallback(function ($object, $id) use ($groupResource) {
            $this->loads[] = $id;
            return $groupResource;
        });

        $collection = $this->createStub(EventCollection::class);
        foreach (['addFieldToFilter', 'setOrder', 'setPageSize', 'setCurPage'] as $method) {
            $collection->method($method)->willReturnCallback(function (...$args) use ($collection, $method) {
                $this->collectionCalls[] = [$method, $args];
                return $collection;
            });
        }
        $collection->method('getItems')->willReturn(['e1' => 'event']);
        $collectionFactory = $this->createStub(EventCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'group', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($select, $method) {
                $this->selectCalls[] = [$method, array_values(array_filter($args, static fn ($a) => $a !== null))];
                return $select;
            });
        }
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchOne')->willReturnCallback(function () {
            $this->countQueries++;
            return (string)$this->eventCount;
        });
        $conn->method('fetchAll')->willReturnCallback(fn () => $this->urlRows);
        $eventResource = $this->createStub(ErrorEventResource::class);
        $eventResource->method('getConnection')->willReturn($conn);
        $eventResource->method('getMainTable')->willReturn('panth_error_event');

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn ($key, $default = null) => $this->params[$key] ?? $default
        );
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            static fn ($route = null, $params = null) => $route . ($params ? '?' . http_build_query($params) : '')
        );

        $block = (new \ReflectionClass(View::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($groupFactory, $groupResource, $collectionFactory, $eventResource, $request, $urlBuilder): void {
            $this->groupFactory = $groupFactory;
            $this->groupResource = $groupResource;
            $this->eventCollectionFactory = $collectionFactory;
            $this->eventResource = $eventResource;
            $this->_request = $request;
            $this->_urlBuilder = $urlBuilder;
        }, $block, View::class)();
        return $block;
    }

    public function testGroupIsLoadedOnceFromRequest(): void
    {
        $this->params = ['group_id' => '7'];
        $block = $this->block();
        $this->assertSame($block->getGroup(), $block->getGroup());
        $this->assertSame([7], $this->loads);
        $this->assertSame(1, $this->factoryCreates);
    }

    public function testGroupWithoutIdIsNotLoaded(): void
    {
        $this->groupId = null;
        $block = $this->block();
        $block->getGroup();
        $this->assertSame([], $this->loads);
        $this->assertSame([], $block->getRecentEvents());
        $this->assertSame([], $block->getDistinctUrls());
        $this->assertSame(0, $block->getTotalEventCount());
        $this->assertSame(1, $block->getTotalPages());
        $this->assertSame(0, $this->countQueries);
    }

    public function testRecentEventsArePaged(): void
    {
        $this->params = ['group_id' => '7', 'page' => '2'];
        $this->eventCount = 120;

        $this->assertSame(['e1' => 'event'], $this->block()->getRecentEvents());
        $this->assertSame([
            ['addFieldToFilter', ['group_id', 7]],
            ['setOrder', ['created_at', 'DESC']],
            ['setPageSize', [View::EVENTS_PER_PAGE]],
            ['setCurPage', [2]],
        ], $this->collectionCalls);
    }

    public function testEventCountIsMemoised(): void
    {
        $this->eventCount = 51;
        $block = $this->block();
        $this->assertSame(51, $block->getTotalEventCount());
        $this->assertSame(51, $block->getTotalEventCount());
        $this->assertSame(1, $this->countQueries);
        $this->assertContains(['where', ['group_id = ?', 7]], $this->selectCalls);
    }

    public function testTotalPagesIsCapped(): void
    {
        $this->eventCount = 120;
        $this->assertSame(3, $this->block()->getTotalPages());
        $this->eventCount = 1000000;
        $this->assertSame(200, $this->block()->getTotalPages());
    }

    public function testCurrentPageIsClamped(): void
    {
        $this->eventCount = 120;
        $this->params = ['page' => '-4'];
        $this->assertSame(1, $this->block()->getCurrentPage());
        $this->params = ['page' => '99'];
        $this->assertSame(3, $this->block()->getCurrentPage());
        $this->params = [];
        $this->assertSame(1, $this->block()->getCurrentPage());
    }

    public function testSinglePageHasNoPagination(): void
    {
        $this->eventCount = 10;
        $block = $this->block();
        $this->assertSame([], $block->getPaginationLinks());
        $this->assertNull($block->getPrevPageUrl());
        $this->assertNull($block->getNextPageUrl());
    }

    public function testPaginationWindowWithGaps(): void
    {
        $this->eventCount = 500;
        $this->params = ['page' => '5'];
        $links = $this->block()->getPaginationLinks();

        $this->assertSame(['1', '...', '3', '4', '5', '6', '7', '...', '10'], array_column($links, 'label'));
        $current = array_values(array_filter($links, static fn ($l) => $l['current']));
        $this->assertSame(5, $current[0]['page']);
        $this->assertSame('panth_errormonitor/error/view?group_id=7&page=5', $current[0]['url']);
        $this->assertSame(['page' => 0, 'url' => '', 'current' => false, 'label' => '...'], $links[1]);
    }

    public function testPaginationAtStartHasNoLeadingGap(): void
    {
        $this->eventCount = 150;
        $block = $this->block();
        $this->assertSame(['1', '2', '3'], array_column($block->getPaginationLinks(), 'label'));
        $this->assertNull($block->getPrevPageUrl());
        $this->assertSame('panth_errormonitor/error/view?group_id=7&page=2', $block->getNextPageUrl());
    }

    public function testPrevAndNextOnLastPage(): void
    {
        $this->eventCount = 150;
        $this->params = ['page' => '3'];
        $block = $this->block();
        $this->assertSame('panth_errormonitor/error/view?group_id=7&page=2', $block->getPrevPageUrl());
        $this->assertNull($block->getNextPageUrl());
    }

    public function testDistinctUrlsAreMapped(): void
    {
        $this->urlRows = [['url' => '/a', 'occurrences' => '5'], ['url' => '/b', 'occurrences' => '1']];
        $this->assertSame(
            [['url' => '/a', 'occurrences' => 5], ['url' => '/b', 'occurrences' => 1]],
            $this->block()->getDistinctUrls()
        );
        $this->assertContains(['limit', [50]], $this->selectCalls);
        $this->assertContains(['group', ['url']], $this->selectCalls);
        $this->assertContains(['order', ['occurrences DESC']], $this->selectCalls);
    }

    public function testActionUrls(): void
    {
        $block = $this->block();
        $this->assertSame('panth_errormonitor/error/index', $block->getBackUrl());
        $this->assertSame('panth_errormonitor/error/resolve?group_id=7', $block->getResolveUrl());
        $this->assertSame('panth_errormonitor/error/ignore?group_id=7', $block->getIgnoreUrl());
    }

    public function testStatusLabel(): void
    {
        $block = $this->block();
        $this->assertSame('New', $block->statusLabel(ErrorGroup::STATUS_NEW));
        $this->assertSame('Resolved', $block->statusLabel(ErrorGroup::STATUS_RESOLVED));
        $this->assertSame('Ignored', $block->statusLabel(ErrorGroup::STATUS_IGNORED));
        $this->assertSame('New', $block->statusLabel(42));
    }

    public function testFormatContext(): void
    {
        $block = $this->block();
        $this->assertSame('', $block->formatContext(null));
        $this->assertSame('', $block->formatContext(''));
        $this->assertSame('not json', $block->formatContext('not json'));
        $this->assertSame('"scalar"', $block->formatContext('"scalar"'));
        $this->assertSame("{\n    \"url\": \"/a/b\"\n}", $block->formatContext('{"url":"\/a\/b"}'));
    }
}
