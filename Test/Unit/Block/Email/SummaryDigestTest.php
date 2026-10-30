<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Block\Email;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\View\Element\Template\Context;
use Panth\ErrorMonitor\Block\Email\Summary;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use Panth\ErrorMonitor\Model\Summary\DigestHolder;
use PHPUnit\Framework\TestCase;

class SummaryDigestTest extends TestCase
{
    private array $builtFor = [];

    protected function tearDown(): void
    {
        (new DigestHolder())->clear();
    }

    private function block(array $data = []): Summary
    {
        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturnCallback(
            static fn ($route, $params) => 'https://admin.test/' . $route . '/id/' . $params['group_id']
        );
        $builder = $this->createStub(DigestBuilder::class);
        $builder->method('build')->willReturnCallback(function ($now, $ids): array {
            $this->builtFor[] = $ids;
            return ['rows' => [['group_id' => 9]], 'timezone' => 'UTC', 'hidden_total' => 0];
        });
        return new Summary(
            $this->createStub(Context::class),
            $this->createStub(CollectionFactory::class),
            $url,
            $data,
            new DigestHolder(),
            $builder
        );
    }

    public function testRowsAndFooterComeFromHeldDigest(): void
    {
        (new DigestHolder())->set([
            'rows' => [['group_id' => 4, 'count_label' => '6', 'count_24h' => 6, 'occurrence_count' => 1702]],
            'timezone' => 'America/Los_Angeles',
            'hidden_total' => 3,
            'hidden_ignored' => 1,
            'hidden_bot' => 2,
            'hidden_graphql' => 0,
            'not_shown' => 4,
        ]);
        $block = $this->block(['group_ids' => '4']);

        $rows = $block->getRows();
        $this->assertSame(4, $rows[0]['group_id']);
        $this->assertSame('6 in last 24 h (1702 total)', $block->formatCounts($rows[0]));
        $this->assertSame('https://admin.test/panth_errormonitor/error/view/id/4', $block->getViewUrl($rows[0]));
        $this->assertSame([
            'Times are shown in the store timezone (America/Los_Angeles). Values are stored in UTC.',
            '3 group(s) with events in the last 24 hours were hidden: 1 by ignore rules, 2 by the bot filter,'
            . ' 0 GraphQL client errors.',
            '4 more group(s) are not listed because of the per-email limit.',
        ], $block->getFooterLines());
        $this->assertSame([], $this->builtFor);
    }

    public function testLegacyGroupIdsBuildDigestOnDemand(): void
    {
        $block = $this->block(['group_ids' => '9,x']);
        $this->assertSame([['group_id' => 9]], $block->getRows());
        $this->assertSame([[9]], $this->builtFor);
        $this->assertContains('No groups were hidden by ignore rules or the bot filter.', $block->getFooterLines());
    }

    public function testNoIdsAndNoDigestRendersNothing(): void
    {
        $this->assertSame([], $this->block()->getRows());
        $this->assertSame([], $this->builtFor);
    }
}
