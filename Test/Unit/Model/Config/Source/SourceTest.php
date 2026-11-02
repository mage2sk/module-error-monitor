<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Model\Config\Source;

use Panth\ErrorMonitor\Model\Config\Source\Source;
use PHPUnit\Framework\TestCase;

class SourceTest extends TestCase
{
    public function testOptionsCoverPhpAndJs(): void
    {
        $options = (new Source())->toOptionArray();
        $this->assertSame(['php', 'js'], array_column($options, 'value'));
        $this->assertSame(['PHP', 'JavaScript'], array_map('strval', array_column($options, 'label')));
    }
}
