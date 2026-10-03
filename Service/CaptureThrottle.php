<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\CacheInterface;

class CaptureThrottle
{
    private const PREFIX = 'panth_em_thr_';

    private const PENDING_TTL = 900;

    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    public function register(string $fingerprint, int $windowSeconds): int
    {
        if ($windowSeconds <= 0) {
            return 1;
        }

        try {
            $bucket  = (int)floor(time() / $windowSeconds);
            $gateKey = self::PREFIX . 'g_' . $fingerprint . '_' . $bucket;
            $pendKey = self::PREFIX . 'p_' . $fingerprint;

            $pendTtl = max(self::PENDING_TTL, $windowSeconds * 2);

            $pending = (int)$this->cache->load($pendKey) + 1;

            if ((string)$this->cache->load($gateKey) === '') {
                $this->cache->save('1', $gateKey, [], max($windowSeconds * 2, 120));
                $this->cache->save('0', $pendKey, [], $pendTtl);
                return $pending;
            }

            $this->cache->save((string)$pending, $pendKey, [], $pendTtl);
            return 0;
        } catch (\Throwable $e) {
            return 1;
        }
    }
}
