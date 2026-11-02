<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Panth\ErrorMonitor\Service\NewGroupLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NewGroupLimiterTest extends TestCase
{
    private array $store = [];

    private array $ttls = [];

    private array $warnings = [];

    private bool $cacheThrows = false;

    private function limiter(): NewGroupLimiter
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function ($id) {
            if ($this->cacheThrows) {
                throw new \RuntimeException('cache down');
            }
            return $this->store[$id] ?? false;
        });
        $cache->method('save')->willReturnCallback(function ($data, $id, $tags = [], $ttl = null): bool {
            $this->store[$id] = (string)$data;
            $this->ttls[$id] = $ttl;
            return true;
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });
        return new NewGroupLimiter($cache, $logger);
    }

    public function testAllowsUpToLimitThenDenies(): void
    {
        $limiter = $this->limiter();
        $this->assertTrue($limiter->allow(3));
        $this->assertTrue($limiter->allow(3));
        $this->assertTrue($limiter->allow(3));
        $this->assertFalse($limiter->allow(3));
        $this->assertFalse($limiter->allow(3));
        $counterKey = 'panth_em_ng_' . (int)floor(time() / 3600);
        $this->assertSame('3', $this->store[$counterKey]);
        $this->assertSame(7200, $this->ttls[$counterKey]);
    }

    public function testWarnsOnlyOncePerWindow(): void
    {
        $limiter = $this->limiter();
        $limiter->allow(1);
        $limiter->allow(1);
        $limiter->allow(1);
        $limiter->allow(1);
        $this->assertCount(1, $this->warnings);
        $this->assertStringStartsWith('[PanthErrorMonitor]', $this->warnings[0]);
        $this->assertStringContainsString('cap of 1 per hour', $this->warnings[0]);
    }

    public function testNonPositiveLimitIsUnlimitedAndTouchesNothing(): void
    {
        $limiter = $this->limiter();
        $this->assertTrue($limiter->allow(0));
        $this->assertTrue($limiter->allow(-5));
        $this->assertSame([], $this->store);
        $this->assertSame([], $this->warnings);
    }

    public function testFailsOpenWhenCacheThrows(): void
    {
        $this->cacheThrows = true;
        $this->assertTrue($this->limiter()->allow(1));
        $this->assertSame([], $this->warnings);
    }
}
