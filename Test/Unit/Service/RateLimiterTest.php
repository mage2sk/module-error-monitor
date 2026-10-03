<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Panth\ErrorMonitor\Service\RateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private array $store = [];

    private array $ttls = [];

    private function limiter(): RateLimiter
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn ($id) => $this->store[$id] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $id, $tags = [], $ttl = null): bool {
            $this->store[$id] = (string)$data;
            $this->ttls[$id] = $ttl;
            return true;
        });
        return new RateLimiter($cache);
    }

    public function testNonPositiveLimitAlwaysDenies(): void
    {
        $limiter = $this->limiter();
        $this->assertFalse($limiter->allow('1.2.3.4', 0));
        $this->assertFalse($limiter->allow('1.2.3.4', -1));
        $this->assertSame([], $this->store);
    }

    public function testPerIpLimitIsEnforced(): void
    {
        $limiter = $this->limiter();
        $this->assertTrue($limiter->allow('1.2.3.4', 2));
        $this->assertTrue($limiter->allow('1.2.3.4', 2));
        $this->assertFalse($limiter->allow('1.2.3.4', 2));
        $this->assertTrue($limiter->allow('5.6.7.8', 2), 'Another IP has its own budget');
    }

    public function testKeysUseHashedIpAndTtl(): void
    {
        $limiter = $this->limiter();
        $limiter->allow('9.9.9.9', 3);

        $bucket = (int)floor(time() / 60);
        $ipKey = 'panth_em_rl_' . $bucket . '_' . hash('sha256', '9.9.9.9');
        $globalKey = 'panth_em_rl_g_' . $bucket;
        $this->assertSame('1', $this->store[$ipKey]);
        $this->assertSame('1', $this->store[$globalKey]);
        $this->assertSame(120, $this->ttls[$ipKey]);
        foreach (array_keys($this->store) as $key) {
            $this->assertStringNotContainsString('9.9.9.9', $key);
        }
    }

    public function testGlobalBudgetBlocksEveryIp(): void
    {
        $limiter = $this->limiter();
        $bucket = (int)floor(time() / 60);
        $this->store['panth_em_rl_g_' . $bucket] = '50';

        $this->assertFalse($limiter->allow('1.1.1.1', 1));
        $this->assertSame('50', $this->store['panth_em_rl_g_' . $bucket]);
    }

    public function testGlobalBudgetIsFiftyTimesThePerIpLimit(): void
    {
        $limiter = $this->limiter();
        $bucket = (int)floor(time() / 60);
        $this->store['panth_em_rl_g_' . $bucket] = '49';

        $this->assertTrue($limiter->allow('1.1.1.1', 1));
        $this->assertFalse($limiter->allow('2.2.2.2', 1));
    }
}
