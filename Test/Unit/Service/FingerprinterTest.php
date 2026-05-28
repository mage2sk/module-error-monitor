<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Panth\ErrorMonitor\Service\Fingerprinter;
use PHPUnit\Framework\TestCase;

class FingerprinterTest extends TestCase
{
    private Fingerprinter $fingerprinter;

    protected function setUp(): void
    {
        $this->fingerprinter = new Fingerprinter();
    }

    public function testVariableNumbersCollapseToSameGroup(): void
    {
        $a = $this->fingerprinter->fingerprint('php', 'RuntimeException', 'Product 123 not found', '/a/File.php', 42);
        $b = $this->fingerprinter->fingerprint('php', 'RuntimeException', 'Product 987 not found', '/a/File.php', 42);
        $this->assertSame($a, $b, 'Messages differing only by a number must share a fingerprint');
    }

    public function testQuotedValuesAndUuidsCollapse(): void
    {
        $a = $this->fingerprinter->fingerprint('php', 'X', 'Token "abc" for 550e8400-e29b-41d4-a716-446655440000');
        $b = $this->fingerprinter->fingerprint('php', 'X', 'Token "zzz" for 11111111-2222-3333-4444-555555555555');
        $this->assertSame($a, $b);
    }

    public function testGenuinelyDifferentMessagesDiffer(): void
    {
        $a = $this->fingerprinter->fingerprint('php', 'RuntimeException', 'Database is down');
        $b = $this->fingerprinter->fingerprint('php', 'RuntimeException', 'Payment gateway timeout');
        $this->assertNotSame($a, $b);
    }

    public function testSourceAndTypeAffectFingerprint(): void
    {
        $php = $this->fingerprinter->fingerprint('php', 'TypeError', 'same message');
        $js = $this->fingerprinter->fingerprint('js', 'TypeError', 'same message');
        $this->assertNotSame($php, $js, 'Source must be part of the fingerprint');
    }

    public function testJsSourceUrlHostAndQueryIgnored(): void
    {
        $a = $this->fingerprinter->fingerprint('js', 'Error', 'boom', 'https://cdn1.example.com/static/app.js?v=1', 10);
        $b = $this->fingerprinter->fingerprint('js', 'Error', 'boom', 'https://cdn2.example.com/static/app.js?v=2', 10);
        $this->assertSame($a, $b, 'CDN host and cache-busting query must not split groups');
    }

    public function testNormalizeMessageStripsStackTrace(): void
    {
        $normalized = $this->fingerprinter->normalizeMessage("Boom happened\nStack trace:\n#0 /x.php(1): foo()");
        $this->assertStringNotContainsString('#0', $normalized);
        $this->assertStringContainsString('boom happened', $normalized);
    }

    public function testFingerprintIsSha256Hex(): void
    {
        $fp = $this->fingerprinter->fingerprint('php', 'X', 'msg');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fp);
    }
}
