<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * One-shot data patch. In 1.5.0 the ignore-patterns config moved from
 * panth_errormonitor/js_capture/ignore_patterns to
 * panth_errormonitor/general/ignore_patterns — and its match scope
 * widened from "message only" to "message + file path + error class +
 * stack trace". This patch copies any existing customer-defined value
 * into the new path on every configured scope so the upgrade is
 * transparent.
 *
 * Idempotent — Magento's patch system records that this patch ran; the
 * runtime reader also falls back to the legacy path until the patch
 * lands, so an admin that captures errors before setup:upgrade still
 * gets their filters applied.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

class MigrateIgnorePatternsToGeneral implements DataPatchInterface
{
    private const LEGACY_PATH = 'panth_errormonitor/js_capture/ignore_patterns';
    private const CANONICAL_PATH = 'panth_errormonitor/general/ignore_patterns';

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
                ->from($table, ['scope', 'scope_id', 'value'])
                ->where('path = ?', self::LEGACY_PATH);
            $rows = $conn->fetchAll($select);
            $copied = 0;
            foreach ($rows as $row) {
                $value = trim((string)$row['value']);
                if ($value === '') {
                    continue;
                }
                $scope = (string)$row['scope'];
                $scopeId = (int)$row['scope_id'];
                // Don't clobber a value the admin may have already set on the
                // new path between deploy and patch run.
                $existing = $conn->fetchOne(
                    $conn->select()
                        ->from($table, 'value')
                        ->where('path = ?', self::CANONICAL_PATH)
                        ->where('scope = ?', $scope)
                        ->where('scope_id = ?', $scopeId)
                );
                if (is_string($existing) && trim($existing) !== '') {
                    continue;
                }
                $this->configWriter->save(self::CANONICAL_PATH, $value, $scope, $scopeId);
                $copied++;
            }
            if ($copied > 0) {
                $this->logger->info(
                    '[PanthErrorMonitor] migrated ignore-patterns to general/ on '
                    . $copied . ' scope(s)'
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthErrorMonitor] ignore-patterns migration failed: ' . $e->getMessage()
            );
        }
        return $this;
    }
}
