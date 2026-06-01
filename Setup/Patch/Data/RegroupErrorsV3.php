<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * One-shot data patch — runs ONCE on setup:upgrade after the module is
 * upgraded to 1.5.0 so existing groups collapse under the new fingerprint
 * rules (JS basename + framework-generic file-agnostic) instead of leaving
 * dozens of stale per-script buckets for the same defect family.
 *
 * Idempotent: Magento's patch system records that this patch has been applied;
 * re-running setup:upgrade is a no-op. RegroupErrorsV2 is listed as a
 * dependency so this patch always runs strictly after the v2 collapse.
 *
 * Failures are caught so a problem in the regrouper never blocks the upgrade,
 * and the merge itself is transactional inside Regrouper — partial state
 * never reaches the DB.
 *
 * NO DATA LOSS: this calls Regrouper::regroupAll() which MOVES events to the
 * surviving canonical row before deleting any peer group, and aggregates
 * occurrence counts / first_seen_at / last_seen_at into the canonical row.
 * The Url-distinct view and the recent-events list both keep working
 * across the merge.
 */
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
