<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup;
use Panth\ErrorMonitor\Model\System\Message\RefingerprintPending;
use Psr\Log\LoggerInterface;

class FlagRefingerprintPending implements DataPatchInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly FlagManager $flagManager,
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
            $table = $this->resource->getTableName(ErrorGroup::TABLE_NAME);
            $hasGroups = (int)$conn->fetchOne('SELECT COUNT(*) FROM (SELECT 1 FROM '
                . $conn->quoteIdentifier($table) . ' LIMIT 1) AS g') > 0;
            if ($hasGroups) {
                $this->flagManager->saveFlag(RefingerprintPending::FLAG_CODE, 1);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthErrorMonitor] could not flag the refingerprint step: ' . $e->getMessage());
        }
        return $this;
    }
}
