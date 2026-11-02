<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\ErrorMonitor\Service\ClientIpResolver;
use PHPUnit\Framework\TestCase;

class ClientIpResolverTest extends TestCase
{
    private function resolver(?string $trusted = null): ClientIpResolver
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn ($path) => $path === ClientIpResolver::XML_TRUSTED_PROXIES ? $trusted : null
        );
        return new ClientIpResolver($scope);
    }

    public function testCloudflareHeaderIsHonoredFromCloudflareRange(): void
    {
        $this->assertSame(
            ['116.179.37.10', ClientIpResolver::SOURCE_CF_CONNECTING_IP],
            $this->resolver()->resolve('162.158.10.20', '116.179.37.10', '9.9.9.9')
        );
        $this->assertSame(
            ['2001:db8::7', ClientIpResolver::SOURCE_CF_CONNECTING_IP],
            $this->resolver()->resolve('2606:4700::6810:1', '2001:db8::7', null)
        );
    }

    public function testSpoofedHeadersFromUntrustedClientAreIgnored(): void
    {
        $this->assertSame(
            ['203.0.113.9', ClientIpResolver::SOURCE_REMOTE_ADDR],
            $this->resolver()->resolve('203.0.113.9', '116.179.37.10', '116.179.37.11')
        );
    }

    public function testForwardedForUsesNearestPublicAddressThatIsNotATrustedProxy(): void
    {
        $this->assertSame(
            ['198.51.100.7', ClientIpResolver::SOURCE_X_FORWARDED_FOR],
            $this->resolver()->resolve('127.0.0.1', null, '1.1.1.1, 198.51.100.7, 10.0.0.5, 162.158.1.1')
        );
    }

    public function testInvalidCloudflareHeaderFallsBackToForwardedFor(): void
    {
        $this->assertSame(
            ['198.51.100.7', ClientIpResolver::SOURCE_X_FORWARDED_FOR],
            $this->resolver()->resolve('10.0.0.2', 'garbage', 'nonsense, 198.51.100.7')
        );
    }

    public function testTrustedProxyWithoutUsableHeadersKeepsRemoteAddr(): void
    {
        $this->assertSame(
            ['10.0.0.2', ClientIpResolver::SOURCE_REMOTE_ADDR],
            $this->resolver()->resolve('10.0.0.2', null, '10.0.0.3, 192.168.1.1')
        );
    }

    public function testEmptyTrustedListNeverReadsHeaders(): void
    {
        $this->assertSame(
            ['162.158.10.20', ClientIpResolver::SOURCE_REMOTE_ADDR],
            $this->resolver('')->resolve('162.158.10.20', '116.179.37.10', null)
        );
    }

    public function testConfiguredListReplacesDefaultsAndInvalidLinesAreSkipped(): void
    {
        $resolver = $this->resolver("bogus\n192.0.2.0/24");
        $this->assertSame(
            ['116.179.37.10', ClientIpResolver::SOURCE_CF_CONNECTING_IP],
            $resolver->resolve('192.0.2.50', '116.179.37.10', null)
        );
        $this->assertSame(
            ['162.158.10.20', ClientIpResolver::SOURCE_REMOTE_ADDR],
            $resolver->resolve('162.158.10.20', '116.179.37.10', null)
        );
    }

    public function testInvalidRemoteAddrResolvesToNull(): void
    {
        $this->assertSame([null, ClientIpResolver::SOURCE_REMOTE_ADDR], $this->resolver()->resolve(null, '1.2.3.4', null));
        $this->assertSame([null, ClientIpResolver::SOURCE_REMOTE_ADDR], $this->resolver()->resolve('x', '1.2.3.4', null));
    }

    public function testResolveFromServerReadsTheServerKeys(): void
    {
        $this->assertSame(
            ['116.179.37.10', ClientIpResolver::SOURCE_CF_CONNECTING_IP],
            $this->resolver()->resolveFromServer([
                'REMOTE_ADDR' => '172.70.1.1',
                'HTTP_CF_CONNECTING_IP' => '116.179.37.10',
            ])
        );
    }
}
