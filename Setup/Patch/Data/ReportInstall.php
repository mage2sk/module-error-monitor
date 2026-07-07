<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\ErrorMonitor\Service\InstallReporter;

class ReportInstall implements DataPatchInterface
{
    public function __construct(
        private readonly InstallReporter $installReporter
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        try {
            $this->installReporter->reportInstall();
        } catch (\Throwable) {
        }
        return $this;
    }
}
