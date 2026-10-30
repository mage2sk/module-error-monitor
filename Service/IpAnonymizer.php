<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Panth\ErrorMonitor\Helper\Config;

class IpAnonymizer
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function process(?string $ip, ?int $storeId = null): ?string
    {
        if ($ip === null) {
            return null;
        }
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (!$this->config->shouldStoreIp($storeId)) {
            return null;
        }
        if (!$this->config->shouldAnonymizeIp($storeId)) {
            return $ip;
        }
        return $this->mask($ip);
    }

    private function mask(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        $masked = substr($packed, 0, 6) . str_repeat("\0", strlen($packed) - 6);
        $result = inet_ntop($masked);
        return $result !== false ? $result : $ip;
    }
}
