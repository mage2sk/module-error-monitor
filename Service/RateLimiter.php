<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Fixed-window rate limiter backed by the default Magento cache. Protects the
 * public JS beacon endpoint from being used to flood the error tables:
 *   - per-IP limit (configurable);
 *   - a global per-minute ceiling so a botnet spread across many IPs still
 *     can't write unbounded rows.
 *
 * Using a per-minute bucket key avoids TTL-reset races: each minute gets its
 * own counter that simply expires.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\CacheInterface;

class RateLimiter
{
    private const PREFIX = 'panth_em_rl_';
    private const TTL = 120;

    /** Global ceiling = per-IP limit * this multiplier. */
    private const GLOBAL_MULTIPLIER = 50;

    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return bool true if the request is allowed, false if it should be dropped.
     */
    public function allow(string $ip, int $perMinuteLimit): bool
    {
        if ($perMinuteLimit <= 0) {
            return false;
        }
        $bucket = (int)floor(time() / 60);

        if (!$this->hit(self::PREFIX . 'g_' . $bucket, $perMinuteLimit * self::GLOBAL_MULTIPLIER)) {
            return false;
        }
        return $this->hit(self::PREFIX . $bucket . '_' . hash('sha256', $ip), $perMinuteLimit);
    }

    private function hit(string $key, int $limit): bool
    {
        $current = (int)$this->cache->load($key);
        if ($current >= $limit) {
            return false;
        }
        $this->cache->save((string)($current + 1), $key, [], self::TTL);
        return true;
    }
}
