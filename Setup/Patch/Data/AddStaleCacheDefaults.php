<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * One-shot data patch. 1.5.4 expanded the default ignore_patterns set with
 * three lines that suppress the post-deploy stale-cache JS family
 * (`Script error for "X"` / `ChunkLoadError` / `Loading chunk N failed
 * after 3 retries`) — these fire as users' browsers hold a stale
 * requirejs-config / webpack manifest after a deploy and produce one
 * group per failed module, none of which the operator can fix.
 *
 * Fresh installs pick the new defaults up automatically from
 * etc/config.xml. Existing installs that already have a customer-edited
 * value in core_config_data would NOT pick them up — that value wins
 * over the default. This patch APPENDS the three lines (deduped against
 * what's already there, so a custom edit is never clobbered) so the
 * upgrade just-works for sites that have been running 1.5.0+ for a while.
 *
 * Idempotent — Magento's patch system records that this patch ran;
 * re-running setup:upgrade is a no-op. Failures are swallowed so a
 * stale config row cannot block the upgrade.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

class AddStaleCacheDefaults implements DataPatchInterface
{
    private const PATH = 'panth_errormonitor/general/ignore_patterns';

    /** New default lines added in 1.5.4. Kept here as the single source of truth. */
    private const ADDED_LINES = [
        'Script error for',
        'ChunkLoadError',
        'Loading chunk',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly WriterInterface $configWriter,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getDependencies(): array
    {
        return [MigrateIgnorePatternsToGeneral::class];
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
            $rows = $conn->fetchAll(
                $conn->select()
                    ->from($table, ['scope', 'scope_id', 'value'])
                    ->where('path = ?', self::PATH)
            );
            $updated = 0;
            foreach ($rows as $row) {
                $existing = (string)$row['value'];
                if (trim($existing) === '') {
                    continue;
                }
                $haveLines = array_map(
                    'trim',
                    preg_split('/\r\n|\r|\n/', $existing) ?: []
                );
                $haveLower = array_map('strtolower', $haveLines);
                $toAppend = [];
                foreach (self::ADDED_LINES as $line) {
                    if (!in_array(strtolower($line), $haveLower, true)) {
                        $toAppend[] = $line;
                    }
                }
                if ($toAppend === []) {
                    continue;
                }
                $merged = rtrim($existing, "\r\n") . "\n" . implode("\n", $toAppend);
                $this->configWriter->save(
                    self::PATH,
                    $merged,
                    (string)$row['scope'],
                    (int)$row['scope_id']
                );
                $updated++;
            }
            if ($updated > 0) {
                $this->logger->info(
                    '[PanthErrorMonitor] appended stale-cache defaults to ignore_patterns on '
                    . $updated . ' scope(s)'
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthErrorMonitor] AddStaleCacheDefaults patch failed: ' . $e->getMessage()
            );
        }
        return $this;
    }
}
