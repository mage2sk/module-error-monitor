<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Runs on every setup:upgrade. The InstallReporter dedupes per version via
 * Magento\Framework\Flag, so re-running is a silent no-op.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Panth\ErrorMonitor\Service\InstallReporter;

class RecurringData implements InstallDataInterface
{
    public function __construct(
        private readonly InstallReporter $reporter
    ) {
    }

    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        $this->reporter->reportInstall();
    }
}
