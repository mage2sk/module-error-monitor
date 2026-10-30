<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;

class RateLimiter
{
    public const TABLE = 'panth_error_rate_limit';

    private const PREFIX = 'panth_em_rl_';
    private const TTL = 120;

    private const GLOBAL_MULTIPLIER = 50;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ?ResourceConnection $resource = null
    ) {
    }

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

    public function purgeExpired(?int $now = null): int
    {
        if ($this->resource === null) {
            return 0;
        }
        $conn = $this->resource->getConnection();
        return (int)$conn->delete(
            $this->resource->getTableName(self::TABLE),
            ['expires_at < ?' => $now ?? time()]
        );
    }

    private function hit(string $key, int $limit): bool
    {
        $hits = $this->atomicHit($key);
        if ($hits !== null) {
            return $hits <= $limit;
        }
        $current = (int)$this->cache->load($key);
        if ($current >= $limit) {
            return false;
        }
        $this->cache->save((string)($current + 1), $key, [], self::TTL);
        return true;
    }

    private function atomicHit(string $key): ?int
    {
        if ($this->resource === null) {
            return null;
        }
        try {
            $conn = $this->resource->getConnection();
            $conn->query(
                'INSERT INTO ' . $conn->quoteIdentifier($this->resource->getTableName(self::TABLE))
                . ' (bucket, hits, expires_at) VALUES (?, LAST_INSERT_ID(1), ?)'
                . ' ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
                [$key, time() + self::TTL]
            );
            $hits = (int)$conn->fetchOne('SELECT LAST_INSERT_ID()');
            return $hits > 0 ? $hits : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
