<?php
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

    public function testUuidsCollapse(): void
    {
        $a = $this->fingerprinter->fingerprint('php', 'X', 'Token for 550e8400-e29b-41d4-a716-446655440000 has expired');
        $b = $this->fingerprinter->fingerprint('php', 'X', 'Token for 11111111-2222-3333-4444-555555555555 has expired');
        $this->assertSame($a, $b);
    }

    public function testFreeTextQuotedValuesCollapse(): void
    {
        $a = $this->fingerprinter->fingerprint('php', 'X', 'Customer said "I want a full refund please"');
        $b = $this->fingerprinter->fingerprint('php', 'X', 'Customer said "Where is my order it is late"');
        $this->assertSame($a, $b, 'Free-text quoted strings must collapse to <v>');
    }

    public function testIdentifierShapedQuotedTokensPreserved(): void
    {
        $a = $this->fingerprinter->fingerprint('php', 'X', "Module 'Vendor_ModuleA' is not enabled");
        $b = $this->fingerprinter->fingerprint('php', 'X', "Module 'Vendor_ModuleB' is not enabled");
        $this->assertNotSame($a, $b, 'Identifier-shaped quoted tokens must NOT false-collapse');

        $c = $this->fingerprinter->fingerprint('php', 'X', "Unknown column 'sku_alt' in 'field list'");
        $d = $this->fingerprinter->fingerprint('php', 'X', "Unknown column 'price_alt' in 'field list'");
        $this->assertNotSame($c, $d, 'Different column names must not collapse');
    }

    public function testEsCausedByDiscriminates(): void
    {
        $a = $this->fingerprinter->fingerprint('php', 'EsException',
            'search failed: {"error":{"root_cause":[{"type":"mapper_parsing_exception"}]}}');
        $b = $this->fingerprinter->fingerprint('php', 'EsException',
            'search failed: {"error":{"root_cause":[{"type":"parse_exception"}]}}');
        $this->assertNotSame($a, $b);
    }

    public function testIpv6CompressionHandled(): void
    {
        $a = $this->fingerprinter->fingerprint('php', 'RuntimeException',
            'upstream connect failed at 2001:db8:85a3::8a2e:370:7334');
        $b = $this->fingerprinter->fingerprint('php', 'RuntimeException',
            'upstream connect failed at fe80::1ff:fe23:4567:890a');
        $this->assertSame($a, $b);
    }

    public function testIpv4InUrlScheme(): void
    {
        $a = $this->fingerprinter->fingerprint('php', 'PDOException',
            'Connection refused at tcp://192.168.1.10:3306');
        $b = $this->fingerprinter->fingerprint('php', 'PDOException',
            'Connection refused at tcp://10.0.0.7:3306');
        $this->assertSame($a, $b);
    }

    public function testStaticAssetCacheBusterStripped(): void
    {
        $a = $this->fingerprinter->fingerprint('js', 'TypeError', "Cannot read 'qty'",
            'https://site.test/static/version1730000000/frontend/Magento/luma/en_US/mage/menu.js', 10);
        $b = $this->fingerprinter->fingerprint('js', 'TypeError', "Cannot read 'qty'",
            'https://site.test/static/version1731111111/frontend/Magento/luma/en_US/mage/menu.js', 10);
        $this->assertSame($a, $b);
    }

    public function testExtractTypeMinesExceptionClass(): void
    {
        $this->assertSame(
            'Magento\\Framework\\Exception\\NoSuchEntityException',
            $this->fingerprinter->extractType(
                "exception 'Magento\\Framework\\Exception\\NoSuchEntityException' with message 'X' in /path:42"
            )
        );

        $this->assertSame('TypeError', $this->fingerprinter->extractType("TypeError: Cannot read 'x' of undefined"));

        $this->assertNull($this->fingerprinter->extractType('a random log line with no class info'));
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

    public function testJsBasenameCollapsesAcrossPaths(): void
    {
        $a = $this->fingerprinter->fingerprint('js', 'Error', 'boom',
            'https://site.test/static/version1/frontend/Magento/luma/en_US/mage/app.js', 5);
        $b = $this->fingerprinter->fingerprint('js', 'Error', 'boom',
            'https://site.test/static/version2/frontend/Vendor/theme/de_DE/other/path/app.js', 5);
        $this->assertSame($a, $b, 'Different theme/locale paths to the same script must collapse on basename');
    }

    public function testFrameworkGenericJsIgnoresFile(): void
    {
        $a = $this->fingerprinter->fingerprint('js', 'TypeError',
            "Cannot set properties of null (setting 'innerHTML')",
            'https://site.test/static/version1/frontend/X/Y/en_US/widget-a.js', 10);
        $b = $this->fingerprinter->fingerprint('js', 'TypeError',
            "Cannot set properties of null (setting 'innerHTML')",
            'https://other.test/static/widget-b.js', 99);
        $c = $this->fingerprinter->fingerprint('js', 'TypeError',
            "Cannot set properties of null (setting 'innerHTML')",
            null, null);
        $this->assertSame($a, $b);
        $this->assertSame($a, $c, 'Even null file must hash the same as a populated one for framework-generic JS');
    }

    public function testFrameworkGenericJsPredicate(): void
    {
        $this->assertTrue($this->fingerprinter->isFrameworkGenericJs(
            "cannot set properties of null (setting 'innerhtml')"
        ));
        $this->assertTrue($this->fingerprinter->isFrameworkGenericJs(
            "cannot read properties of undefined (reading 'qty')"
        ));
        $this->assertTrue($this->fingerprinter->isFrameworkGenericJs(
            "t.\$_widget.api is not a function"
        ));
        $this->assertTrue($this->fingerprinter->isFrameworkGenericJs(
            "failed to execute 'insertbefore' on 'node':"
        ));
        $this->assertFalse($this->fingerprinter->isFrameworkGenericJs(
            'uncaught error: script error for "magento_theme/js/theme"'
        ));
    }

    public function testPhpFileStillFullPath(): void
    {
        $a = $this->fingerprinter->fingerprint('php', 'TypeError', 'boom',
            '/var/www/vhosts/site/vendor/foo/Helper/A.php', 10);
        $b = $this->fingerprinter->fingerprint('php', 'TypeError', 'boom',
            '/var/www/vhosts/site/vendor/foo/Helper/B.php', 10);
        $this->assertNotSame($a, $b, 'PHP file differences must still split groups');
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
