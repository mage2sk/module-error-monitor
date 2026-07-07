<?php
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
