<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Daily heartbeat. No-op when Panth_Core is enabled, since Core sends a
 * single heartbeat for the whole suite and we don't want every sibling
 * module's cron stampeding the receiver.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Cron;

use Magento\Framework\Module\Manager as ModuleManager;
use Panth\ErrorMonitor\Service\InstallReporter;

class SendHeartbeat
{
    public function __construct(
        private readonly InstallReporter $reporter,
        private readonly ModuleManager $moduleManager
    ) {
    }

    public function execute(): void
    {
        if ($this->moduleManager->isEnabled('Panth_Core')) {
            return;
        }
        $this->reporter->reportHeartbeat();
    }
}
