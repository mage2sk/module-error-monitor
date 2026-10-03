<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\CacheInterface;

class RateLimiter
{
    private const PREFIX = 'panth_em_rl_';
    private const TTL = 120;

    private const GLOBAL_MULTIPLIER = 50;

    public function __construct(
        private readonly CacheInterface $cache
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
