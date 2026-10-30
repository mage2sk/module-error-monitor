<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\ErrorMonitor\Service\Regrouper;
use Psr\Log\LoggerInterface;

class RegroupErrorsV3 implements DataPatchInterface
{
    public function __construct(
        private readonly Regrouper $regrouper,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getDependencies(): array
    {
        return [RegroupErrorsV2::class];
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
                '[PanthErrorMonitor] regroup v3 completed: '
                . json_encode($stats, JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthErrorMonitor] regroup v3 failed: ' . $e->getMessage());
        }
        return $this;
    }
}
