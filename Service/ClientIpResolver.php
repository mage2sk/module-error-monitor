<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;

class ClientIpResolver
{
    public const XML_TRUSTED_PROXIES = 'panth_errormonitor/filters/trusted_proxies';

    public const SOURCE_REMOTE_ADDR = 'remote_addr';

    public const SOURCE_CF_CONNECTING_IP = 'cf-connecting-ip';

    public const SOURCE_X_FORWARDED_FOR = 'x-forwarded-for';

    private const MAX_FORWARDED_ENTRIES = 20;

    public const DEFAULT_TRUSTED_PROXIES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '::1/128',
        'fc00::/7',
    ];

    private ?array $trusted = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function resolve(?string $remoteAddr, ?string $cfConnectingIp, ?string $forwardedFor): array
    {
        $remoteAddr = $remoteAddr !== null ? trim($remoteAddr) : '';
        if (!IpRange::isValidIp($remoteAddr)) {
            return [null, self::SOURCE_REMOTE_ADDR];
        }
        if (!$this->isTrustedProxy($remoteAddr)) {
            return [$remoteAddr, self::SOURCE_REMOTE_ADDR];
        }
        $cf = $cfConnectingIp !== null ? trim($cfConnectingIp) : '';
        if (IpRange::isValidIp($cf)) {
            return [$cf, self::SOURCE_CF_CONNECTING_IP];
        }
        $forwarded = $this->fromForwardedFor($forwardedFor);
        if ($forwarded !== null) {
            return [$forwarded, self::SOURCE_X_FORWARDED_FOR];
        }
        return [$remoteAddr, self::SOURCE_REMOTE_ADDR];
    }

    public function resolveFromServer(array $server): array
    {
        return $this->resolve(
            self::stringValue($server['REMOTE_ADDR'] ?? null),
            self::stringValue($server['HTTP_CF_CONNECTING_IP'] ?? null),
            self::stringValue($server['HTTP_X_FORWARDED_FOR'] ?? null)
        );
    }

    public function isTrustedProxy(string $ip): bool
    {
        if ($this->trusted === null) {
            $this->trusted = IpRange::parseList($this->trustedLines());
        }
        return IpRange::contains($this->trusted, $ip);
    }

    private function fromForwardedFor(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }
        $entries = array_slice(array_reverse(explode(',', $header)), 0, self::MAX_FORWARDED_ENTRIES);
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if (!IpRange::isValidIp($entry) || $this->isTrustedProxy($entry)) {
                continue;
            }
            if (filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $entry;
            }
        }
        return null;
    }

    private function trustedLines(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_TRUSTED_PROXIES);
        if ($raw === null) {
            return self::DEFAULT_TRUSTED_PROXIES;
        }
        return BotDetector::splitLines((string)$raw);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
