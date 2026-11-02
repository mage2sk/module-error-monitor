<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use PHPUnit\Framework\TestCase;

class IpAnonymizerCoverageTest extends TestCase
{
    private function anonymizer(bool $store, bool $anonymize): IpAnonymizer
    {
        $config = $this->createStub(Config::class);
        $config->method('shouldStoreIp')->willReturn($store);
        $config->method('shouldAnonymizeIp')->willReturn($anonymize);
        return new IpAnonymizer($config);
    }

    public function testBlankAndWhitespaceInputReturnsNull(): void
    {
        $anonymizer = $this->anonymizer(true, false);
        $this->assertNull($anonymizer->process(''));
        $this->assertNull($anonymizer->process('   '));
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        $this->assertSame('10.0.0.5', $this->anonymizer(true, false)->process("  10.0.0.5\n"));
    }

    public function testIpv6KeptVerbatimWhenAnonymisationDisabled(): void
    {
        $this->assertSame('2001:db8::1', $this->anonymizer(true, false)->process('2001:db8::1'));
    }

    public function testIpv6MaskedToFirstFortyEightBits(): void
    {
        $this->assertSame('2001:db8:abcd::', $this->anonymizer(true, true)->process('2001:db8:abcd:12::ff'));
    }

    public function testStoreIdIsPassedToConfig(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects($this->once())->method('shouldStoreIp')->with(3)->willReturn(true);
        $config->expects($this->once())->method('shouldAnonymizeIp')->with(3)->willReturn(true);
        $this->assertSame('192.168.1.0', (new IpAnonymizer($config))->process('192.168.1.200', 3));
    }
}
