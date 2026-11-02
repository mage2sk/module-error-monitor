<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

class NewGroupLimiter
{
    private const PREFIX = 'panth_em_ng_';

    private const WINDOW = 3600;

    private const TTL = 7200;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function allow(int $perHourLimit): bool
    {
        if ($perHourLimit <= 0) {
            return true;
        }
        try {
            $bucket = (int)floor(time() / self::WINDOW);
            $key = self::PREFIX . $bucket;
            $current = (int)$this->cache->load($key);
            if ($current < $perHourLimit) {
                $this->cache->save((string)($current + 1), $key, [], self::TTL);
                return true;
            }
            $this->warnOnce($bucket, $perHourLimit);
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function warnOnce(int $bucket, int $perHourLimit): void
    {
        $key = self::PREFIX . 'w_' . $bucket;
        if ((string)$this->cache->load($key) !== '') {
            return;
        }
        $this->cache->save('1', $key, [], self::TTL);
        $this->logger->warning(sprintf(
            '%s Storefront JS new error group cap of %d per hour reached. New JS error groups are dropped'
            . ' until the next hour; occurrences of existing groups are still counted.',
            ErrorRecorder::INTERNAL_MARKER,
            $perHourLimit
        ));
    }
}
