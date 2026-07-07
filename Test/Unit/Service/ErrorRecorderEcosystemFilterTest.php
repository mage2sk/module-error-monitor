<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

class ErrorRecorderEcosystemFilterTest extends TestCase
{
    private const PATTERN = '/^\[Panth[A-Z][A-Za-z0-9_]{0,40}\]\s+(?:BLOCKED|REJECTED|DENIED|REFUSED|DROPPED|QUARANTINED)\b/';

    public function testMatchesEcosystemAlerts(string $message): void
    {
        $this->assertSame(1, preg_match(self::PATTERN, $message), "Should match: $message");
    }

    public static function matchingMessages(): array
    {
        return [
            ['[PanthMalwareScanner] BLOCKED frontend dispatch: uri=/wp-content/.../update'],
            ['[PanthMalwareScanner] BLOCKED media serve: path=/media/wysiwyg/x'],
            ['[PanthFirewall] REJECTED request from ip 1.2.3.4'],
            ['[PanthBotShield] DENIED user-agent "..."'],
            ['[PanthRateLimiter] REFUSED request, quota exceeded'],
            ['[PanthQuarantine] QUARANTINED upload, size=12345'],
            ['[PanthAntivirus] DROPPED connection'],
        ];
    }

    public function testDoesNotMatchUnrelatedMessages(string $message): void
    {
        $this->assertSame(0, preg_match(self::PATTERN, $message), "Should NOT match: $message");
    }

    public static function nonMatchingMessages(): array
    {
        return [
            ['[PanthErrorMonitor] some internal warning'],
            ['[Magento_Catalog] product saved'],
            ['[panthmalwarescanner] BLOCKED ...'],
            ['BLOCKED something'],
            ['exception in [Panth_Whatever] BLOCKED ...'],
            ['[Panth] BLOCKED ...'],
            ['Magento\Framework\Exception\LocalizedException: ...'],
        ];
    }
}
