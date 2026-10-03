<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Ui\Component\Listing\Column;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Panth\ErrorMonitor\Ui\Component\Listing\Column\Actions;
use PHPUnit\Framework\TestCase;

class ActionsTest extends TestCase
{
    private function column(): Actions
    {
        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturnCallback(
            static fn ($route, $params) => '/admin/' . $route . '/group_id/' . $params['group_id']
        );
        return new Actions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $url,
            [],
            ['name' => 'actions']
        );
    }

    public function testDataSourceWithoutItemsIsUnchanged(): void
    {
        $source = ['data' => ['totalRecords' => 0]];
        $this->assertSame($source, $this->column()->prepareDataSource($source));
    }

    public function testActionsAreAddedForEachRow(): void
    {
        $result = $this->column()->prepareDataSource([
            'data' => ['items' => [
                ['group_id' => '5', 'message' => 'x'],
                ['message' => 'no id'],
            ]],
        ]);

        $items = $result['data']['items'];
        $this->assertArrayNotHasKey('actions', $items[1]);

        $actions = $items[0]['actions'];
        $this->assertSame(['view', 'resolve', 'ignore', 'delete'], array_keys($actions));
        $this->assertSame('/admin/panth_errormonitor/error/view/group_id/5', $actions['view']['href']);
        $this->assertSame('View', (string)$actions['view']['label']);
        $this->assertArrayNotHasKey('post', $actions['view']);
        $this->assertSame('/admin/panth_errormonitor/error/resolve/group_id/5', $actions['resolve']['href']);
        $this->assertTrue($actions['resolve']['post']);
        $this->assertSame('/admin/panth_errormonitor/error/ignore/group_id/5', $actions['ignore']['href']);
        $this->assertTrue($actions['ignore']['post']);
        $this->assertSame('/admin/panth_errormonitor/error/delete/group_id/5', $actions['delete']['href']);
        $this->assertTrue($actions['delete']['post']);
        $this->assertSame('Delete error', (string)$actions['delete']['confirm']['title']);
        $this->assertSame(
            'Delete this error group and all its recorded occurrences?',
            (string)$actions['delete']['confirm']['message']
        );
        $this->assertSame('x', $items[0]['message']);
    }
}
