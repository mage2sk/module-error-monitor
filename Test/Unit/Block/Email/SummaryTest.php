<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Block\Email;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template\Context;
use Panth\ErrorMonitor\Block\Email\Summary;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\Collection;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SummaryTest extends TestCase
{
    private int $created = 0;

    private array $calls = [];

    private function block(array $data = []): Summary
    {
        $collection = $this->createStub(Collection::class);
        foreach (['addFieldToFilter', 'setOrder'] as $method) {
            $collection->method($method)->willReturnCallback(function (...$args) use ($collection, $method) {
                $this->calls[] = [$method, $args];
                return $collection;
            });
        }
        $collection->method('getItems')->willReturn([3 => 'group3']);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($collection) {
            $this->created++;
            return $collection;
        });

        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturnCallback(
            static fn ($route, $params) => 'https://admin.test/' . $route . '/id/' . $params['group_id']
        );

        return new Summary($this->createStub(Context::class), $factory, $url, $data);
    }

    public function testNoIdsReturnsNoGroups(): void
    {
        $this->assertSame([], $this->block()->getErrorGroups());
        $this->assertSame([], $this->block(['group_ids' => 'a,0,,'])->getErrorGroups());
        $this->assertSame(0, $this->created);
    }

    public function testGroupsAreLoadedByIds(): void
    {
        $this->assertSame([3 => 'group3'], $this->block(['group_ids' => '3, 5,x,0'])->getErrorGroups());
        $this->assertSame([
            ['addFieldToFilter', ['group_id', ['in' => [3, 5]]]],
            ['setOrder', ['severity', 'DESC']],
            ['setOrder', ['occurrence_count', 'DESC']],
        ], $this->calls);
    }

    public function testViewUrl(): void
    {
        $this->assertSame(
            'https://admin.test/panth_errormonitor/error/view/id/9',
            $this->block()->getViewUrl(new DataObject(['group_id' => '9']))
        );
    }

    public static function colorProvider(): array
    {
        return [
            ['emergency', '#b91c1c'],
            ['ALERT', '#b91c1c'],
            ['critical', '#b91c1c'],
            ['Error', '#c2410c'],
            ['warning', '#b45309'],
            ['notice', '#475569'],
            ['', '#475569'],
        ];
    }

    #[DataProvider('colorProvider')]
    public function testSeverityColor(string $severity, string $color): void
    {
        $this->assertSame($color, $this->block()->severityColor($severity));
    }

    public function testShorten(): void
    {
        $block = $this->block();
        $this->assertSame('short', $block->shorten('short', 10));
        $this->assertSame('exactly10!', $block->shorten('exactly10!', 10));
        $this->assertSame('abc...', $block->shorten('abcdef', 3));
        $this->assertSame(str_repeat("\u{e9}", 3) . '...', $block->shorten(str_repeat("\u{e9}", 6), 3));
    }
}
