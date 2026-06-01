<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * One-shot data patch. The "immediate digest" email mode was removed in
 * 1.5.0 because in production it still emitted one email per hour during
 * sustained incidents, which the module exists to prevent. This patch
 * rewrites any existing core_config_data row that selected the deprecated
 * value to the only remaining mode (daily_summary) on every configured
 * scope so the admin does not have to touch the panel after the upgrade.
 *
 * Idempotent: Magento's patch system records that this patch ran; re-running
 * setup:upgrade is a no-op. Failures are swallowed so a stale config row
 * cannot block the upgrade.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\ErrorMonitor\Model\Config\Source\EmailMode;
use Psr\Log\LoggerInterface;

class MigrateEmailModeToDaily implements DataPatchInterface
{
    private const CONFIG_PATH = 'panth_errormonitor/email/mode';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly WriterInterface $configWriter,
        private readonly LoggerInterface $logger
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
            $conn = $this->resource->getConnection();
            $table = $this->resource->getTableName('core_config_data');
            $select = $conn->select()
                ->from($table, ['scope', 'scope_id'])
                ->where('path = ?', self::CONFIG_PATH)
                ->where('value = ?', EmailMode::MODE_IMMEDIATE);
            $rows = $conn->fetchAll($select);
            foreach ($rows as $row) {
                $this->configWriter->save(
                    self::CONFIG_PATH,
                    EmailMode::MODE_DAILY,
                    (string)$row['scope'],
                    (int)$row['scope_id']
                );
            }
            if ($rows) {
                $this->logger->info(
                    '[PanthErrorMonitor] migrated email mode to daily_summary on '
                    . count($rows) . ' scope(s)'
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthErrorMonitor] email-mode migration failed: ' . $e->getMessage()
            );
        }
        return $this;
    }
}
