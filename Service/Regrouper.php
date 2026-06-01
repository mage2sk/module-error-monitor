<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Re-runs the (current) Fingerprinter over the existing panth_error_group
 * rows and merges any that now share a fingerprint. Used by the one-shot
 * data patch on setup:upgrade and by the on-demand console command, so an
 * upgrade with a tightened normaliser automatically collapses historical
 * data instead of leaving orphan groups behind forever.
 *
 * Algorithm:
 *   1. Walk groups in first_seen_at ASC order; the oldest in each new-fp
 *      bucket becomes the canonical row.
 *   2. For each bucket, aggregate occurrence_count / first_seen / last_seen
 *      from the non-canonical peers, then UPDATE canonical with the new
 *      fingerprint + extracted type + merged stats.
 *   3. Re-point events from the peers to canonical.
 *   4. DELETE the peers.
 *
 * Wrapped in a transaction; on failure the whole regroup rolls back so the
 * table is never left half-merged.
 */
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

    /**
     * Plan re-grouping without modifying anything.
     *
     * @return array{scanned:int, would_update:int, would_merge:int, would_delete:int}
     */
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

    /**
     * Execute the regroup. Idempotent — re-running on an already-tight set is a
     * cheap no-op.
     *
     * @return array{scanned:int, updated:int, merged:int, deleted_groups:int, events_moved:int}
     */
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
            // 1. Merge buckets (canonical id => peer ids).
            foreach ($plan['merges'] as $canonicalId => $peerIds) {
                $canonical = $plan['canonical_data'][$canonicalId];
                $merged = $this->mergeBucket($conn, $groupTable, $eventTable, $canonicalId, $peerIds, $canonical);
                $stats['merged']++;
                $stats['deleted_groups'] += count($peerIds);
                $stats['events_moved']   += $merged['events_moved'];
            }
            // 2. Apply solo updates (no peers but fingerprint/type changed).
            foreach ($plan['updates'] as $gid => $upd) {
                // Skip if this group was a canonical that already got updated by mergeBucket.
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

    /**
     * Channel-ish / placeholder type names that carry no triage value —
     * safe to overwrite with whatever extractType mines from the message.
     */
    private const GENERIC_TYPES = ['', 'main', 'report', 'error', 'exception', 'throwable'];

    private function isGenericType(string $type): bool
    {
        return in_array(strtolower(trim($type)), self::GENERIC_TYPES, true);
    }

    /**
     * Walk the table once and compute what would change.
     *
     * @return array{
     *   scanned:int,
     *   updates: array<int, array{fingerprint:string, error_type:string}>,
     *   merges:  array<int, array<int,int>>,
     *   canonical_data: array<int, array{fingerprint:string, error_type:string}>
     * }
     */
    private function buildPlan(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setOrder('first_seen_at', 'ASC')->setOrder('group_id', 'ASC');

        $byNewFp = [];          // new_fp => canonical group_id
        $merges = [];           // canonical_id => [peer_ids]
        $updates = [];          // group_id => [fingerprint, error_type]
        $canonicalData = [];    // canonical_id => [new fingerprint + type to write]
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

            // Try to mine a sharper type out of the stored message — but ONLY
            // when the stored type is itself useless (channel-name fallback or
            // empty). A meaningful FQN like
            // "Elasticsearch\Common\Exceptions\BadRequest400Exception" must
            // NOT be overwritten with whatever extractType happens to pull
            // (e.g. the ES caused_by tag is more specific but less actionable
            // for an admin scanning the grid).
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

    /**
     * Merge a single bucket — aggregate counts/dates, re-point events, delete
     * peers, update canonical (last so the unique fingerprint slot is free).
     *
     * @param array<string,string> $canonical New fp/type to set on canonical.
     * @param int[]                $peerIds
     * @return array{events_moved:int}
     */
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
