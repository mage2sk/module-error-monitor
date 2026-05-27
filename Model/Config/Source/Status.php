<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\ErrorMonitor\Model\ErrorGroup;

class Status implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: int, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => ErrorGroup::STATUS_NEW, 'label' => __('New')],
            ['value' => ErrorGroup::STATUS_RESOLVED, 'label' => __('Resolved')],
            ['value' => ErrorGroup::STATUS_IGNORED, 'label' => __('Ignored')],
        ];
    }
}
