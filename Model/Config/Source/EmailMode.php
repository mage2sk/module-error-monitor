<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EmailMode implements OptionSourceInterface
{
    /** One summary email per day at a fixed hour (recommended — no inbox flooding). */
    public const MODE_DAILY = 'daily_summary';

    /** A digest sent as new errors arrive (each group at most once per day). */
    public const MODE_IMMEDIATE = 'immediate_digest';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::MODE_DAILY, 'label' => __('Daily summary — one email per day (recommended)')],
            ['value' => self::MODE_IMMEDIATE, 'label' => __('Immediate digest — as new errors arrive')],
        ];
    }
}
