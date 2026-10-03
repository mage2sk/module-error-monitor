<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Psr\Log\LoggerInterface;

class Regrouper
{
    public function __construct(
        private readonly ErrorGroupResource $groupResource,
        private readonly ErrorEventResource $eventResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly Fingerprinter $fingerprinter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function plan(): array
    {
        $plan = $this->buildPlan();
        return [
            'scanned'       => $plan['scanned'],
            'would_update'  => count($plan['updates']),
            'would_merge'   => count($plan['merges']),
            'would_delete'  => array_sum(array_map('count', $plan['merges'])),
        ];
    }

    public function regroupAll(): array
    {
        $stats = ['scanned' => 0, 'updated' => 0, 'merged' => 0, 'deleted_groups' => 0, 'events_moved' => 0];
        $plan = $this->buildPlan();
        $stats['scanned'] = $plan['scanned'];
        if ($plan['scanned'] === 0) {
            return $stats;
        }

        $conn = $this->groupResource->getConnection();
        $groupTable = $this->groupResource->getMainTable();
        $eventTable = $this->eventResource->getMainTable();

        $conn->beginTransaction();
        try {
            foreach ($plan['merges'] as $canonicalId => $peerIds) {
                $canonical = $plan['canonical_data'][$canonicalId];
                $merged = $this->mergeBucket($conn, $groupTable, $eventTable, $canonicalId, $peerIds, $canonical);
                $stats['merged']++;
                $stats['deleted_groups'] += count($peerIds);
                $stats['events_moved']   += $merged['events_moved'];
            }

            foreach ($plan['updates'] as $gid => $upd) {
                if (isset($plan['merges'][$gid])) {
                    continue;
                }
                $conn->update($groupTable, $upd, ['group_id = ?' => $gid]);
                $stats['updated']++;
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->warning('[PanthErrorMonitor] regroup rolled back: ' . $e->getMessage());
            throw $e;
        }
        return $stats;
    }

    private const GENERIC_TYPES = ['', 'main', 'report', 'error', 'exception', 'throwable'];

    private function isGenericType(string $type): bool
    {
        return in_array(strtolower(trim($type)), self::GENERIC_TYPES, true);
    }

    private function buildPlan(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setOrder('first_seen_at', 'ASC')->setOrder('group_id', 'ASC');

        $byNewFp = [];
        $merges = [];
        $updates = [];
        $canonicalData = [];
        $scanned = 0;

        foreach ($collection as $g) {
            $scanned++;
            $gid = (int)$g->getData('group_id');
            $oldFp = (string)$g->getData('fingerprint');
            $oldType = (string)$g->getData('error_type');
            $source = (string)$g->getData('source');
            $message = (string)$g->getData('message');
            $file = (string)$g->getData('file');
            $line = $g->getData('line') !== null ? (int)$g->getData('line') : null;

            $newType = $oldType;
            if ($this->isGenericType($oldType)) {
                $extracted = $this->fingerprinter->extractType($message);
                if ($extracted !== null && $extracted !== '') {
                    $newType = $extracted;
                }
            }

            $newFp = $this->fingerprinter->fingerprint($source, $newType, $message, $file, $line);

            if (!isset($byNewFp[$newFp])) {
                $byNewFp[$newFp] = $gid;
                $canonicalData[$gid] = ['fingerprint' => $newFp, 'error_type' => $newType];
                if ($newFp !== $oldFp || $newType !== $oldType) {
                    $updates[$gid] = ['fingerprint' => $newFp, 'error_type' => $newType];
                }
            } else {
                $merges[$byNewFp[$newFp]][] = $gid;
            }
        }

        return [
            'scanned'        => $scanned,
            'updates'        => $updates,
            'merges'         => $merges,
            'canonical_data' => $canonicalData,
        ];
    }

    private function mergeBucket(
        \Magento\Framework\DB\Adapter\AdapterInterface $conn,
        string $groupTable,
        string $eventTable,
        int $canonicalId,
        array $peerIds,
        array $canonical
    ): array {
        $allIds = array_merge([$canonicalId], $peerIds);
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));

        $row = $conn->fetchRow(
            "SELECT SUM(occurrence_count) AS oc, MIN(first_seen_at) AS first_seen,"
            . " MAX(last_seen_at) AS last_seen, MAX(emailed_count) AS ec,"
            . " MAX(last_emailed_date) AS led"
            . " FROM " . $conn->quoteIdentifier($groupTable)
            . " WHERE group_id IN ($placeholders)",
            $allIds
        );

        $eventsMoved = (int)$conn->update(
            $eventTable,
            ['group_id' => $canonicalId],
            ['group_id IN (?)' => $peerIds]
        );

        $conn->delete($groupTable, ['group_id IN (?)' => $peerIds]);

        $conn->update(
            $groupTable,
            [
                'fingerprint'       => $canonical['fingerprint'],
                'error_type'        => $canonical['error_type'],
                'occurrence_count'  => (int)$row['oc'],
                'first_seen_at'     => $row['first_seen'],
                'last_seen_at'      => $row['last_seen'],
                'emailed_count'     => (int)$row['ec'],
                'last_emailed_date' => $row['led'],
            ],
            ['group_id = ?' => $canonicalId]
        );

        return ['events_moved' => $eventsMoved];
    }
}
