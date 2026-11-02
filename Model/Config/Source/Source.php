<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\ErrorMonitor\Model\ErrorGroup;

class Source implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ErrorGroup::SOURCE_PHP, 'label' => __('PHP')],
            ['value' => ErrorGroup::SOURCE_JS, 'label' => __('JavaScript')],
        ];
    }
}
