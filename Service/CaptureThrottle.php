<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Coalescing throttle for the PHP capture path.
 *
 * A single hot exception thrown on every request — or in a tight loop — would
 * otherwise drive one UPDATE on panth_error_group plus one INSERT on
 * panth_error_event per occurrence. At high frequency that is a large, steady
 * stream of row writes: heavy on the DB and, because every write is row-logged,
 * heavy on the binary log. This collapses that to at most one DB write per
 * fingerprint per time window, while keeping occurrence_count accurate by
 * carrying the coalesced count forward and flushing it on the next write.
 *
 * Cache-backed (the default Magento cache, typically Redis): absorbing the
 * flood in cache keeps it off MySQL entirely. Fixed-window bucket keys avoid
 * TTL-reset races — each window has its own gate key that simply expires.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\CacheInterface;

class CaptureThrottle
{
    private const PREFIX = 'panth_em_thr_';

    /** Safety expiry for an un-flushed pending counter (seconds). */
    private const PENDING_TTL = 900;

    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * Register one occurrence of $fingerprint and decide how many occurrences
     * should be persisted right now.
     *
     * @return int >=1  caller MUST write to the DB and increment
     *                  occurrence_count by exactly this many (it includes every
     *                  occurrence coalesced since the previous flush);
     *              0   this occurrence was absorbed into the cache and must NOT
     *                  touch the DB.
     *
     * Within each $windowSeconds bucket only the first occurrence of a given
     * fingerprint writes; the rest are counted in cache and flushed by the
     * first write of the following window, so the stored count stays accurate
     * to within at most one in-flight window. A non-positive window disables
     * throttling entirely (every occurrence writes — legacy behaviour).
     */
    public function register(string $fingerprint, int $windowSeconds): int
    {
        if ($windowSeconds <= 0) {
            return 1;
        }

        try {
            $bucket  = (int)floor(time() / $windowSeconds);
            $gateKey = self::PREFIX . 'g_' . $fingerprint . '_' . $bucket;
            $pendKey = self::PREFIX . 'p_' . $fingerprint;

            // The pending counter has to outlive the window it is carrying, or
            // a long window would lose mid-window counts to TTL expiry.
            $pendTtl = max(self::PENDING_TTL, $windowSeconds * 2);

            // Accumulate this occurrence on top of anything coalesced so far.
            $pending = (int)$this->cache->load($pendKey) + 1;

            // First occurrence in this window flushes the whole accumulated count.
            if ((string)$this->cache->load($gateKey) === '') {
                $this->cache->save('1', $gateKey, [], max($windowSeconds * 2, 120));
                $this->cache->save('0', $pendKey, [], $pendTtl);
                return $pending;
            }

            // Same window, already written — just carry the count forward.
            $this->cache->save((string)$pending, $pendKey, [], $pendTtl);
            return 0;
        } catch (\Throwable $e) {
            // Fail OPEN: if the cache is unavailable, record the occurrence
            // rather than dropping it. Losing throttling during a cache outage
            // is far less harmful than going blind to errors.
            return 1;
        }
    }
}
