<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EmailMode implements OptionSourceInterface
{
    public const MODE_DIGEST = 'digest';
    public const MODE_INDIVIDUAL = 'individual';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::MODE_DIGEST, 'label' => __('Digest (one email per run)')],
            ['value' => self::MODE_INDIVIDUAL, 'label' => __('Individual (one email per error)')],
        ];
    }
}
