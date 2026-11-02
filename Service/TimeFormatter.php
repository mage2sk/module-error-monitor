<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;

class TimeFormatter
{
    public const FORMAT = 'Y-m-d H:i:s T';

    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function storeTimezone($storeId = null): string
    {
        try {
            $zone = (string)$this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, $storeId);
        } catch (\Throwable $e) {
            $zone = '';
        }
        return $this->isValidZone($zone) ? $zone : 'UTC';
    }

    public function format(?string $utc, string $timezone, string $format = self::FORMAT): string
    {
        if ($utc === null || trim($utc) === '' || str_starts_with($utc, '0000-00-00')) {
            return '';
        }
        try {
            $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            $zone = new \DateTimeZone($this->isValidZone($timezone) ? $timezone : 'UTC');
            return $date->setTimezone($zone)->format($format);
        } catch (\Exception $e) {
            return $utc . ' UTC';
        }
    }

    public function utcNow(?int $timestamp = null): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp ?? time());
    }

    public function utcHoursAgo(int $hours, ?int $now = null): string
    {
        return gmdate('Y-m-d H:i:s', ($now ?? time()) - $hours * 3600);
    }

    private function isValidZone(string $zone): bool
    {
        if ($zone === '') {
            return false;
        }
        try {
            new \DateTimeZone($zone);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
