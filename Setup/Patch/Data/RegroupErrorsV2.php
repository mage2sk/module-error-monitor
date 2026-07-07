<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\ErrorMonitor\Service\Regrouper;
use Psr\Log\LoggerInterface;

class RegroupErrorsV2 implements DataPatchInterface
{
    public function __construct(
        private readonly Regrouper $regrouper,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getDependencies(): array
    {
        return [ReportInstall::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        try {
            $stats = $this->regrouper->regroupAll();
            $this->logger->info(
                '[PanthErrorMonitor] regroup completed: '
                . json_encode($stats, JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthErrorMonitor] regroup failed: ' . $e->getMessage());
        }
        return $this;
    }
}
