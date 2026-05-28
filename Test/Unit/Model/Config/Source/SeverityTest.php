<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Model\Config\Source;

use Panth\ErrorMonitor\Model\Config\Source\Severity;
use PHPUnit\Framework\TestCase;

class SeverityTest extends TestCase
{
    public function testRankOrderingIsAscendingBySeverity(): void
    {
        $this->assertGreaterThan(Severity::rank('warning'), Severity::rank('error'));
        $this->assertGreaterThan(Severity::rank('error'), Severity::rank('critical'));
        $this->assertGreaterThan(Severity::rank('critical'), Severity::rank('emergency'));
    }

    public function testRankIsCaseInsensitive(): void
    {
        $this->assertSame(Severity::rank('ERROR'), Severity::rank('error'));
    }

    public function testUnknownSeverityRanksZero(): void
    {
        $this->assertSame(0, Severity::rank('totally-made-up'));
    }

    public function testThresholdComparison(): void
    {
        // An "error" should pass a "min = warning" gate but fail "min = critical".
        $this->assertGreaterThanOrEqual(Severity::rank('warning'), Severity::rank('error'));
        $this->assertLessThan(Severity::rank('critical'), Severity::rank('error'));
    }

    public function testOptionArrayHasExpectedValues(): void
    {
        $values = array_column((new Severity())->toOptionArray(), 'value');
        $this->assertContains('error', $values);
        $this->assertContains('critical', $values);
        $this->assertContains('emergency', $values);
    }
}
