<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Panth\ErrorMonitor\Service\IpRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpRangeCommentsTest extends TestCase
{
    public function testCommentsAreIgnoredAndNeverReportedAsInvalid(): void
    {
        $lines = ['# Baidu crawler networks', '116.179.0.0/16   # inline', '#2001:db8::/32', '220.181.0.0/16'];
        $this->assertSame([], IpRange::invalidLines($lines));
        $ranges = IpRange::parseList($lines);
        $this->assertCount(2, $ranges);
        $this->assertTrue(IpRange::contains($ranges, '116.179.37.10'));
        $this->assertTrue(IpRange::contains($ranges, '220.181.1.1'));
        $this->assertFalse(IpRange::contains($ranges, '2001:db8::1'));
    }

    public function testInvalidLinesAreReportedWithTheirComment(): void
    {
        $this->assertSame(
            ['not-an-ip # typo', '10.0.0.0/40'],
            IpRange::invalidLines(['not-an-ip # typo', '10.0.0.0/40', '10.0.0.0/8'])
        );
        $this->assertCount(1, IpRange::parseList(['not-an-ip', '10.0.0.0/8', '10.0.0.0/40']));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function mappedCases(): array
    {
        return [
            'mapped /112 vs v4 address' => ['::ffff:116.179.0.0/112', '116.179.37.10', true],
            'mapped /112 vs mapped address' => ['::ffff:116.179.0.0/112', '::ffff:116.179.37.10', true],
            'mapped /112 outside' => ['::ffff:116.179.0.0/112', '116.180.0.1', false],
            'mapped /128 exact' => ['::ffff:203.0.113.7/128', '203.0.113.7', true],
            'mapped /96 matches all v4' => ['::ffff:0.0.0.0/96', '198.51.100.1', true],
            'mapped bare address' => ['::ffff:203.0.113.7', '203.0.113.7', true],
            'v4 range vs mapped address' => ['116.179.0.0/16', '::ffff:116.179.1.1', true],
        ];
    }

    /**
     * @dataProvider mappedCases
     */
    #[DataProvider('mappedCases')]
    public function testIpv4MappedRanges(string $range, string $ip, bool $expected): void
    {
        $this->assertNotNull(IpRange::parse($range), $range . ' must be accepted');
        $this->assertSame($expected, IpRange::contains(IpRange::parseList([$range]), $ip));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidMapped(): array
    {
        return [
            'mapped prefix below 96' => ['::ffff:116.179.0.0/64'],
            'mapped prefix above 128' => ['::ffff:116.179.0.0/129'],
            'v4 prefix above 32' => ['116.179.0.0/33'],
        ];
    }

    /**
     * @dataProvider invalidMapped
     */
    #[DataProvider('invalidMapped')]
    public function testInvalidPrefixesAreRejected(string $range): void
    {
        $this->assertNull(IpRange::parse($range));
        $this->assertSame([$range], IpRange::invalidLines([$range]));
    }
}
