<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\ErrorMonitor\Service\TimeFormatter;
use PHPUnit\Framework\TestCase;

class TimeFormatterTest extends TestCase
{
    private function formatter(string $zone = ''): TimeFormatter
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn($zone);
        return new TimeFormatter($timezone);
    }

    public function testUtcValueIsShownInStoreTimezone(): void
    {
        $formatter = $this->formatter();
        $this->assertSame(
            '2026-10-27 18:48:58 PDT',
            $formatter->format('2026-10-28 01:48:58', 'America/Los_Angeles')
        );
        $this->assertSame(
            '2026-10-28 07:18:58 IST',
            $formatter->format('2026-10-28 01:48:58', 'Asia/Kolkata')
        );
        $this->assertSame('2026-01-15 04:00:00 PST', $formatter->format('2026-01-15 12:00:00', 'America/Los_Angeles'));
    }

    public function testInvalidOrEmptyValues(): void
    {
        $formatter = $this->formatter();
        $this->assertSame('', $formatter->format(null, 'UTC'));
        $this->assertSame('', $formatter->format('', 'UTC'));
        $this->assertSame('', $formatter->format('0000-00-00 00:00:00', 'UTC'));
        $this->assertSame('2026-10-28 01:48:58 UTC', $formatter->format('2026-10-28 01:48:58', 'Not/AZone'));
        $this->assertSame('garbage UTC', $formatter->format('garbage', 'UTC'));
    }

    public function testStoreTimezoneComesFromConfig(): void
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('Europe/London');
        $this->assertSame('Europe/London', (new TimeFormatter($timezone))->storeTimezone());

        $broken = $this->createStub(TimezoneInterface::class);
        $broken->method('getConfigTimezone')->willReturn('Mars/Base');
        $this->assertSame('UTC', (new TimeFormatter($broken))->storeTimezone());
        $this->assertSame('UTC', $this->formatter()->storeTimezone());
    }

    public function testWindowIsComputedInUtc(): void
    {
        $formatter = $this->formatter();
        $now = gmmktime(10, 30, 0, 10, 29, 2026);
        $this->assertSame('2026-10-28 10:30:00', $formatter->utcHoursAgo(24, $now));
        $this->assertSame('2026-10-29 10:30:00', $formatter->utcNow($now));
    }
}
