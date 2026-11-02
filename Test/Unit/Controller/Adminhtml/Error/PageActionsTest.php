<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Controller\Adminhtml\Error;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Panth\ErrorMonitor\Controller\Adminhtml\Error\Index;
use Panth\ErrorMonitor\Controller\Adminhtml\Error\View;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ErrorGroupFactory;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use PHPUnit\Framework\TestCase;

class PageActionsTest extends TestCase
{
    use BackendContextTrait;

    private array $titles = [];

    private array $menus = [];

    private array $loaded = [];

    private ?Page $page = null;

    private function pageFactory(): PageFactory
    {
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($text): void {
            $this->titles[] = (string)$text;
        });
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('setActiveMenu')->willReturnCallback(function ($menu) use ($page) {
            $this->menus[] = $menu;
            return $page;
        });
        $page->method('getConfig')->willReturn($config);
        $this->page = $page;
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);
        return $factory;
    }

    private function view(?int $existingId): View
    {
        $group = $this->createStub(ErrorGroup::class);
        $group->method('getId')->willReturn($existingId);
        $factory = $this->createStub(ErrorGroupFactory::class);
        $factory->method('create')->willReturn($group);
        $resource = $this->createStub(ErrorGroupResource::class);
        $resource->method('load')->willReturnCallback(function ($object, $id) use ($resource) {
            $this->loaded[] = $id;
            return $resource;
        });
        return new View($this->backendContext(), $this->pageFactory(), $factory, $resource);
    }

    public function testIndexRendersGridPage(): void
    {
        $controller = new Index($this->backendContext(), $this->pageFactory());
        $this->assertSame($this->page, $controller->execute());
        $this->assertSame(['Panth_ErrorMonitor::error_log'], $this->menus);
        $this->assertSame(['Error Log'], $this->titles);
        $this->assertAclResource($controller, 'Panth_ErrorMonitor::view');
    }

    public function testViewRendersExistingGroup(): void
    {
        $this->params = ['group_id' => '21'];
        $controller = $this->view(21);
        $this->assertSame($this->page, $controller->execute());
        $this->assertSame([21], $this->loaded);
        $this->assertSame(['Panth_ErrorMonitor::error_log'], $this->menus);
        $this->assertSame(['Error #21'], $this->titles);
        $this->assertSame([], $this->messages);
    }

    public function testViewWithoutIdRedirects(): void
    {
        $result = $this->view(null)->execute();
        $this->assertSame($this->redirect, $result);
        $this->assertSame([], $this->loaded);
        $this->assertSame('*/*/index', $this->redirectPath);
        $this->assertSame([['error', 'This error no longer exists.']], $this->messages);
    }

    public function testViewOfMissingGroupRedirects(): void
    {
        $this->params = ['group_id' => '404'];
        $result = $this->view(null)->execute();
        $this->assertSame($this->redirect, $result);
        $this->assertSame([404], $this->loaded);
        $this->assertSame([], $this->titles);
    }

    public function testViewAclResource(): void
    {
        $this->assertAclResource($this->view(null), 'Panth_ErrorMonitor::view');
    }
}
