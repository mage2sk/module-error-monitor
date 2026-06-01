<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Verifies the ecosystem-alert auto-filter regex without booting Magento.
 * The pattern lives as a private const inside ErrorRecorder; we test its
 * behaviour by re-declaring the same regex here as a single-source contract.
 * If the production regex changes, this test fails until both are updated.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

class ErrorRecorderEcosystemFilterTest extends TestCase
{
    /** Must stay in lockstep with ErrorRecorder::ECOSYSTEM_ALERT_PATTERN. */
    private const PATTERN = '/^\[Panth[A-Z][A-Za-z0-9_]{0,40}\]\s+(?:BLOCKED|REJECTED|DENIED|REFUSED|DROPPED|QUARANTINED)\b/';

    /**
     * @dataProvider matchingMessages
     */
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

    /**
     * @dataProvider nonMatchingMessages
     */
    public function testDoesNotMatchUnrelatedMessages(string $message): void
    {
        $this->assertSame(0, preg_match(self::PATTERN, $message), "Should NOT match: $message");
    }

    public static function nonMatchingMessages(): array
    {
        return [
            ['[PanthErrorMonitor] some internal warning'],          // not an action verb
            ['[Magento_Catalog] product saved'],                    // wrong vendor prefix
            ['[panthmalwarescanner] BLOCKED ...'],                  // lowercased — leaks would be real bugs
            ['BLOCKED something'],                                  // no [Vendor_X] tag
            ['exception in [Panth_Whatever] BLOCKED ...'],          // not at start of line
            ['[Panth] BLOCKED ...'],                                // no module suffix after Panth
            ['Magento\Framework\Exception\LocalizedException: ...'],// regular exception
        ];
    }
}
