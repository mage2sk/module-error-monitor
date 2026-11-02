<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

class AddStaleCacheDefaults implements DataPatchInterface
{
    private const PATH = 'panth_errormonitor/general/ignore_patterns';

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
