<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Model\Config\Source;

use Panth\ErrorMonitor\Model\Config\Source\EmailMode;
use PHPUnit\Framework\TestCase;

class EmailModeTest extends TestCase
{
    public function testOnlyDailyModeIsOffered(): void
    {
        $options = (new EmailMode())->toOptionArray();
        $this->assertCount(1, $options);
        $this->assertSame(EmailMode::MODE_DAILY, $options[0]['value']);
        $this->assertSame('daily_summary', EmailMode::MODE_DAILY);
        $this->assertSame('immediate_digest', EmailMode::MODE_IMMEDIATE);
        $this->assertNotSame('', (string)$options[0]['label']);
    }
}
