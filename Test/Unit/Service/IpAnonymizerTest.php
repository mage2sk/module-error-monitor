<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IpAnonymizerTest extends TestCase
{
    private $config;

    private IpAnonymizer $anonymizer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->anonymizer = new IpAnonymizer($this->config);
    }

    private function configure(bool $store, bool $anonymize): void
    {
        $this->config->method('shouldStoreIp')->willReturn($store);
        $this->config->method('shouldAnonymizeIp')->willReturn($anonymize);
    }

    public function testNullInputReturnsNull(): void
    {
        $this->assertNull($this->anonymizer->process(null));
    }

    public function testStorageDisabledReturnsNull(): void
    {
        $this->configure(false, true);
        $this->assertNull($this->anonymizer->process('8.8.8.8'));
    }

    public function testInvalidIpReturnsNull(): void
    {
        $this->configure(true, false);
        $this->assertNull($this->anonymizer->process('not-an-ip'));
    }

    public function testIpv4AnonymisationMasksLastOctet(): void
    {
        $this->configure(true, true);
        $this->assertSame('203.0.113.0', $this->anonymizer->process('203.0.113.45'));
    }

    public function testIpv4FullWhenAnonymiseDisabled(): void
    {
        $this->configure(true, false);
        $this->assertSame('203.0.113.45', $this->anonymizer->process('203.0.113.45'));
    }

    public function testIpv6AnonymisationKeepsPrefix(): void
    {
        $this->configure(true, true);
        $result = $this->anonymizer->process('2001:db8:1234:5678:9abc:def0:1234:5678');
        $this->assertNotNull($result);
        $this->assertStringStartsWith('2001:db8:1234:', (string)$result);
        $this->assertStringNotContainsString('9abc', (string)$result);
    }
}
