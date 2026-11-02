<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Summary;

class DigestHolder
{
    private static ?array $digest = null;

    public function set(array $digest): void
    {
        self::$digest = $digest;
    }

    public function get(): ?array
    {
        return self::$digest;
    }

    public function clear(): void
    {
        self::$digest = null;
    }
}
