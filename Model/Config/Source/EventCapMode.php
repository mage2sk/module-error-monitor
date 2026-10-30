<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\ErrorMonitor\Service\ErrorRecorder;

class EventCapMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ErrorRecorder::EVENT_CAP_KEEP_NEWEST, 'label' => __('Keep the newest rows (replace the oldest)')],
            ['value' => ErrorRecorder::EVENT_CAP_KEEP_OLDEST, 'label' => __('Keep the oldest rows (stop adding)')],
        ];
    }
}
