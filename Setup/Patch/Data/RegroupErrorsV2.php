<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * One-shot data patch — runs ONCE on setup:upgrade after the module is
 * upgraded to the version that ships the tightened normaliser, so existing
 * error_group rows automatically collapse instead of leaving stale buckets.
 *
 * Idempotent: Magento's patch system records that this patch has been applied;
 * re-running setup:upgrade is a no-op. Failures are caught so a problem in the
 * regrouper never blocks the upgrade.
 */
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
            // Never block setup:upgrade if regroup has trouble.
            $this->logger->warning('[PanthErrorMonitor] regroup failed: ' . $e->getMessage());
        }
        return $this;
    }
}
