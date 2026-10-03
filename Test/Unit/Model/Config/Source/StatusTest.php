<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Model\Config\Source;

use Panth\ErrorMonitor\Model\Config\Source\Status;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
{
    public function testOptionsMatchGroupStatuses(): void
    {
        $options = (new Status())->toOptionArray();
        $this->assertSame([0, 1, 2], array_column($options, 'value'));
        $this->assertSame(['New', 'Resolved', 'Ignored'], array_map('strval', array_column($options, 'label')));
    }
}
