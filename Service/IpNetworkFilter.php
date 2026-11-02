<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

class IpNetworkFilter
{
    public const XML_ENABLED = 'panth_errormonitor/filters/ip_filter_enabled';

    public const XML_NETWORKS = 'panth_errormonitor/filters/ip_networks';

    public const XML_AS_BOTS = 'panth_errormonitor/filters/ip_networks_as_bots';

    public const COUNTER_TABLE = 'panth_error_filter_hourly';

    public const REASON_IP = 'ip';

    public const CONTEXT_FLAG = 'ip_network_match';

    public const PASS = 'pass';

    public const DROP = 'drop';

    public const BOT = 'bot';

    public const DEFAULT_NETWORKS = [
        '116.179.0.0/16',
        '220.181.0.0/16',
    ];

    private ?array $ranges = null;

    private readonly ClientIpResolver $resolver;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ?ResourceConnection $resource = null,
        ?ClientIpResolver $resolver = null
    ) {
        $this->resolver = $resolver ?? new ClientIpResolver($scopeConfig);
    }

    public function resolver(): ClientIpResolver
    {
        return $this->resolver;
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED);
    }

    public function decide(?string $ip): string
    {
        if ($ip === null || !$this->isEnabled()) {
            return self::PASS;
        }
        if ($this->ranges === null) {
            $this->ranges = IpRange::parseList($this->networkLines());
        }
        if (!IpRange::contains($this->ranges, $ip)) {
            return self::PASS;
        }
        return $this->scopeConfig->isSetFlag(self::XML_AS_BOTS) ? self::BOT : self::DROP;
    }

    public function countHidden(?int $now = null): void
    {
        if ($this->resource === null) {
            return;
        }
        try {
            $conn = $this->resource->getConnection();
            $conn->query(
                'INSERT INTO ' . $conn->quoteIdentifier($this->resource->getTableName(self::COUNTER_TABLE))
                . ' (hour_start, reason, occurrences) VALUES (?, ?, 1)'
                . ' ON DUPLICATE KEY UPDATE occurrences = occurrences + 1',
                [gmdate('Y-m-d H:00:00', $now ?? time()), self::REASON_IP]
            );
        } catch (\Throwable $e) {
            return;
        }
    }

    public function hiddenSince(string $sinceUtc): int
    {
        if ($this->resource === null) {
            return 0;
        }
        try {
            $conn = $this->resource->getConnection();
            $hourStart = substr($sinceUtc, 0, 13) . ':00:00';
            return (int)$conn->fetchOne(
                'SELECT COALESCE(SUM(occurrences), 0) FROM '
                . $conn->quoteIdentifier($this->resource->getTableName(self::COUNTER_TABLE))
                . ' WHERE reason = ? AND hour_start >= ?',
                [self::REASON_IP, $hourStart]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function purgeBefore(string $cutoffUtc): int
    {
        if ($this->resource === null) {
            return 0;
        }
        $conn = $this->resource->getConnection();
        return (int)$conn->delete(
            $this->resource->getTableName(self::COUNTER_TABLE),
            ['hour_start < ?' => $cutoffUtc]
        );
    }

    private function networkLines(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_NETWORKS);
        if ($raw === null) {
            return self::DEFAULT_NETWORKS;
        }
        return BotDetector::splitLines((string)$raw);
    }
}
