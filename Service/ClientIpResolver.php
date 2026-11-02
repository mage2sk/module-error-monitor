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

    /**
     * Empty: proxy headers are only read for addresses an administrator listed explicitly.
     */
    public const DEFAULT_TRUSTED_PROXIES = [];

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
