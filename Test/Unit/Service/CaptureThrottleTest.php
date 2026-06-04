<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Contract tests for the PHP-capture coalescing throttle. Uses an in-memory
 * fake of the Magento cache so no application bootstrap is required.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Panth\ErrorMonitor\Service\CaptureThrottle;
use PHPUnit\Framework\TestCase;

class CaptureThrottleTest extends TestCase
{
    /** @var array<string,string> */
    private array $store = [];

    private function makeThrottle(): CaptureThrottle
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn($id) => $this->store[$id] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $id): bool {
            $this->store[$id] = (string)$data;
            return true;
        });
        return new CaptureThrottle($cache);
    }

    public function testFirstOccurrenceWritesAndRepeatsInSameWindowCoalesce(): void
    {
        $throttle = $this->makeThrottle();
        $fp = str_repeat('a', 64);

        // First hit in the window is written...
        $this->assertSame(1, $throttle->register($fp, 60));
        // ...every subsequent hit in the same window is absorbed.
        $this->assertSame(0, $throttle->register($fp, 60));
        $this->assertSame(0, $throttle->register($fp, 60));
    }

    public function testCoalescedOccurrencesAccumulateForTheNextFlush(): void
    {
        $throttle = $this->makeThrottle();
        $fp = str_repeat('d', 64);

        $throttle->register($fp, 60); // flush → pending reset to 0
        $throttle->register($fp, 60); // coalesced → pending 1
        $throttle->register($fp, 60); // coalesced → pending 2

        // The carried count the next window's first write will fold in.
        $this->assertSame('2', $this->store['panth_em_thr_p_' . $fp]);
    }

    public function testZeroWindowDisablesThrottlingAndAlwaysWrites(): void
    {
        $throttle = $this->makeThrottle();
        $fp = str_repeat('b', 64);

        $this->assertSame(1, $throttle->register($fp, 0));
        $this->assertSame(1, $throttle->register($fp, 0));
        $this->assertSame(1, $throttle->register($fp, -5));
    }

    public function testFailsOpenWhenCacheThrows(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willThrowException(new \RuntimeException('cache unavailable'));
        $throttle = new CaptureThrottle($cache);

        // A cache outage must never drop the error — record it.
        $this->assertSame(1, $throttle->register(str_repeat('c', 64), 60));
    }
}
