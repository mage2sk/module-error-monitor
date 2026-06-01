<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * As of 1.5.0 the only delivery mode is the once-per-day summary — the
 * previous "immediate digest" mode was removed because in production it
 * could still emit one email per hour during sustained incidents, which
 * was the exact behaviour this module was created to prevent. Existing
 * installs are migrated to daily_summary by a one-shot data patch
 * (MigrateEmailModeToDaily). The class is kept so the system.xml source
 * model and any third-party code that referenced the constants still
 * resolve cleanly.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EmailMode implements OptionSourceInterface
{
    /** One summary email per day at a fixed hour — the only supported mode. */
    public const MODE_DAILY = 'daily_summary';

    /**
     * Removed in 1.5.0. Kept as a constant so any external code that
     * referenced it does not fatal; the dispatcher treats every value
     * other than MODE_DAILY as MODE_DAILY.
     *
     * @deprecated
     */
    public const MODE_IMMEDIATE = 'immediate_digest';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::MODE_DAILY, 'label' => __('Daily summary — one email per day')],
        ];
    }
}
