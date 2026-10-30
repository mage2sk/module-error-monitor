<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EmailMode implements OptionSourceInterface
{
    public const MODE_DAILY = 'daily_summary';

    public const MODE_IMMEDIATE = 'immediate_digest';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::MODE_DAILY, 'label' => __('Daily summary - one email per day')],
        ];
    }
}
