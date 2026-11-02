<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Panth\ErrorMonitor\Service\IpRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpRangeTest extends TestCase
{
    public static function ipv4Provider(): array
    {
        return [
            'inside /16' => ['116.179.0.0/16', '116.179.37.10', true],
            'last address of /16' => ['116.179.0.0/16', '116.179.255.255', true],
            'outside /16' => ['116.179.0.0/16', '116.180.0.1', false],
            'host bits in the network are ignored' => ['116.179.37.10/16', '116.179.1.1', true],
            'odd prefix /20 inside' => ['173.245.48.0/20', '173.245.63.255', true],
            'odd prefix /20 outside' => ['173.245.48.0/20', '173.245.64.0', false],
            'single address' => ['203.0.113.9', '203.0.113.9', true],
            'single address other' => ['203.0.113.9', '203.0.113.10', false],
            '/32' => ['203.0.113.9/32', '203.0.113.9', true],
            '/0 matches every IPv4' => ['0.0.0.0/0', '8.8.8.8', true],
            'IPv4-mapped IPv6 client' => ['116.179.0.0/16', '::ffff:116.179.37.10', true],
            'IPv6 client never matches IPv4 range' => ['116.179.0.0/16', '2001:db8::1', false],
        ];
    }

    #[DataProvider('ipv4Provider')]
    public function testIpv4Matching(string $range, string $ip, bool $expected): void
    {
        $this->assertSame($expected, IpRange::contains(IpRange::parseList([$range]), $ip));
    }

    public static function ipv6Provider(): array
    {
        return [
            'inside /32' => ['2600:3c01::/32', '2600:3c01:abcd::1', true],
            'outside /32' => ['2600:3c01::/32', '2600:3c02::1', false],
            '/29 inside' => ['2a06:98c0::/29', '2a06:98c7:ffff::1', true],
            '/29 outside' => ['2a06:98c0::/29', '2a06:98c8::1', false],
            'fc00::/7 covers fd00::' => ['fc00::/7', 'fd12:3456::1', true],
            'single address' => ['::1', '::1', true],
            'uppercase and compressed forms' => ['2001:DB8::/48', '2001:0db8:0001::1', false],
            'compressed form inside' => ['2001:DB8::/48', '2001:0db8:0000:0000:ffff::1', true],
            'IPv4 client never matches IPv6 range' => ['::/0', '1.2.3.4', false],
        ];
    }

    #[DataProvider('ipv6Provider')]
    public function testIpv6Matching(string $range, string $ip, bool $expected): void
    {
        $this->assertSame($expected, IpRange::contains(IpRange::parseList([$range]), $ip));
    }

    public function testInvalidLinesAreSkippedAndValidOnesStillMatch(): void
    {
        $lines = [
            'not-an-ip',
            '116.179.0.0/33',
            '116.179.0.0/-1',
            '116.179.0.0/abc',
            '1.2.3.4/8/9',
            '256.1.1.1',
            '2600:3c01::/129',
            '2600:zz::/32',
            '',
            '   ',
            ' 220.181.0.0/16 ',
            '2600:3c01::/32',
        ];
        $ranges = IpRange::parseList($lines);

        $this->assertCount(2, $ranges);
        $this->assertTrue(IpRange::contains($ranges, '220.181.5.6'));
        $this->assertTrue(IpRange::contains($ranges, '2600:3c01::5'));
        $this->assertFalse(IpRange::contains($ranges, '116.179.37.10'));
        $this->assertSame(
            ['not-an-ip', '116.179.0.0/33', '116.179.0.0/-1', '116.179.0.0/abc', '1.2.3.4/8/9', '256.1.1.1',
                '2600:3c01::/129', '2600:zz::/32'],
            IpRange::invalidLines($lines)
        );
    }

    public function testInvalidClientAddressNeverMatches(): void
    {
        $ranges = IpRange::parseList(['0.0.0.0/0', '::/0']);
        $this->assertFalse(IpRange::contains($ranges, null));
        $this->assertFalse(IpRange::contains($ranges, ''));
        $this->assertFalse(IpRange::contains($ranges, 'unknown'));
        $this->assertFalse(IpRange::contains($ranges, '1.2.3.4, 5.6.7.8'));
        $this->assertFalse(IpRange::contains([], '1.2.3.4'));
    }
}
