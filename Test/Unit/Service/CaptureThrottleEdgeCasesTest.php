<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Panth\ErrorMonitor\Service\CaptureThrottle;
use PHPUnit\Framework\TestCase;

class CaptureThrottleEdgeCasesTest extends TestCase
{
    private array $store = [];

    private array $ttls = [];

    private function throttle(): CaptureThrottle
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn ($id) => $this->store[$id] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $id, $tags = [], $ttl = null): bool {
            $this->store[$id] = (string)$data;
            $this->ttls[$id] = $ttl;
            return true;
        });
        return new CaptureThrottle($cache);
    }

    private function dropGateKeys(): void
    {
        foreach (array_keys($this->store) as $key) {
            if (str_starts_with($key, 'panth_em_thr_g_')) {
                unset($this->store[$key]);
            }
        }
    }

    public function testNextWindowFlushesCoalescedCount(): void
    {
        $throttle = $this->throttle();
        $fp = str_repeat('e', 64);

        $this->assertSame(1, $throttle->register($fp, 60));
        $this->assertSame(0, $throttle->register($fp, 60));
        $this->assertSame(0, $throttle->register($fp, 60));

        $this->dropGateKeys();

        $this->assertSame(3, $throttle->register($fp, 60));
        $this->assertSame('0', $this->store['panth_em_thr_p_' . $fp]);
    }

    public function testShortWindowUsesMinimumTtls(): void
    {
        $throttle = $this->throttle();
        $fp = str_repeat('f', 64);
        $throttle->register($fp, 30);

        $gateKey = 'panth_em_thr_g_' . $fp . '_' . (int)floor(time() / 30);
        $this->assertSame(120, $this->ttls[$gateKey]);
        $this->assertSame(900, $this->ttls['panth_em_thr_p_' . $fp]);
    }

    public function testLongWindowScalesTtls(): void
    {
        $throttle = $this->throttle();
        $fp = str_repeat('9', 64);
        $throttle->register($fp, 3600);

        $gateKey = 'panth_em_thr_g_' . $fp . '_' . (int)floor(time() / 3600);
        $this->assertSame(7200, $this->ttls[$gateKey]);
        $this->assertSame(7200, $this->ttls['panth_em_thr_p_' . $fp]);
    }

    public function testDifferentFingerprintsAreIndependent(): void
    {
        $throttle = $this->throttle();
        $this->assertSame(1, $throttle->register(str_repeat('1', 64), 60));
        $this->assertSame(1, $throttle->register(str_repeat('2', 64), 60));
    }
}
