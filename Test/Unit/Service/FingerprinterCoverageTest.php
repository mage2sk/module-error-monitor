<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Panth\ErrorMonitor\Service\Fingerprinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FingerprinterCoverageTest extends TestCase
{
    private Fingerprinter $fingerprinter;

    protected function setUp(): void
    {
        $this->fingerprinter = new Fingerprinter();
    }

    public function testShortName(): void
    {
        $this->assertSame('Foo', $this->fingerprinter->shortName('Vendor\\Module\\Foo'));
        $this->assertSame('Bare', $this->fingerprinter->shortName('Bare'));
    }

    public static function typeProvider(): array
    {
        return [
            'namespaced exception prefix' => [
                'Vendor\\Module\\Thing Exception: failed to load',
                'Vendor\\Module\\Thing',
            ],
            'class colon' => ['RuntimeException: boom', 'RuntimeException'],
            'class with message' => ['Foo\\BarError with message something', 'Foo\\BarError'],
            'exception trace frame' => [
                'Exception Trace: #0 /a/b.php(12): Vendor\\Mod\\Cls->run()',
                'Vendor\\Mod\\Cls',
            ],
            'uncaught js error' => ['Uncaught ReferenceError: x is not defined', 'ReferenceError'],
            'invalid template' => [
                "Invalid template file: 'foo.phtml' in module: 'Vendor_Module'",
                'Vendor_Module',
            ],
            'bracket channel' => ['[PanthSeo] ERROR could not render', 'PanthSeo'],
            'es caused by' => [
                'Search failed {"caused_by": {"type": "search_phase_execution_exception"}}',
                'search_phase_execution_exception',
            ],
            'es root cause' => [
                'Search failed {"error": {"root_cause": [{"type": "index_not_found_exception"}]}}',
                'index_not_found_exception',
            ],
            'php warning' => ['Warning: Undefined array key "x"', 'Warning'],
            'deprecated functionality' => ['Deprecated Functionality: old call', 'Deprecated Functionality'],
            'leading whitespace' => ["   TypeError: x", 'TypeError'],
        ];
    }

    #[DataProvider('typeProvider')]
    public function testExtractTypePatterns(string $message, string $expected): void
    {
        $this->assertSame($expected, $this->fingerprinter->extractType($message));
    }

    public function testExtractTypeOnBlankMessage(): void
    {
        $this->assertNull($this->fingerprinter->extractType(''));
        $this->assertNull($this->fingerprinter->extractType("   \n"));
    }

    public function testGenericTypeIsReplacedByMinedType(): void
    {
        $message = 'RuntimeException: boom';
        $explicit = $this->fingerprinter->fingerprint('php', 'RuntimeException', $message);
        $this->assertSame($explicit, $this->fingerprinter->fingerprint('php', '', $message));
        $this->assertSame($explicit, $this->fingerprinter->fingerprint('php', 'main', $message));
        $this->assertSame($explicit, $this->fingerprinter->fingerprint('php', ' Exception ', $message));
        $this->assertNotSame($explicit, $this->fingerprinter->fingerprint('php', 'Custom', $message));
    }

    public function testTypeComparisonIsCaseInsensitive(): void
    {
        $this->assertSame(
            $this->fingerprinter->fingerprint('php', 'FooError', 'x'),
            $this->fingerprinter->fingerprint('php', 'fooerror', 'x')
        );
    }

    public function testNormalizeAppendsElasticsearchCauses(): void
    {
        $normalized = $this->fingerprinter->normalizeMessage(
            'ES failure {"caused_by":{"type":"Too_Many_Clauses"},"root_cause":[{"type":"Query_Shard"}]}'
        );
        $this->assertStringEndsWith(' caused_by=too_many_clauses root_cause=query_shard', $normalized);
    }

    public function testNormalizeDropsChainedNextLines(): void
    {
        $normalized = $this->fingerprinter->normalizeMessage(
            "First problem\nNext Magento\\Framework\\Exception\\LocalizedException:\nSecond"
        );
        $this->assertStringContainsString('first problem', $normalized);
        $this->assertStringContainsString('second', $normalized);
        $this->assertStringNotContainsString('localizedexception', $normalized);
    }

    public function testNormalizeIsCappedAtFiveHundredCharacters(): void
    {
        $normalized = $this->fingerprinter->normalizeMessage(str_repeat('word ', 400));
        $this->assertSame(500, mb_strlen($normalized));
    }

    public function testNormalizeCollapsesVolatileTokens(): void
    {
        $normalized = $this->fingerprinter->normalizeMessage(
            'Failure on line 42 at position 17 in v2.4.7 for parameter #3 order 123456789 with 5 items'
        );
        $this->assertStringContainsString('on line <line>', $normalized);
        $this->assertStringContainsString('at position <pos>', $normalized);
        $this->assertStringContainsString('v<v>', $normalized);
        $this->assertStringContainsString('parameter #<pos>', $normalized);
        $this->assertStringContainsString('order <id>', $normalized);
        $this->assertStringContainsString('with <n> items', $normalized);
    }

    public function testNormalizeReplacesUrlsAndSessionIds(): void
    {
        $normalized = $this->fingerprinter->normalizeMessage(
            'Request to https://api.example.com/v1/x failed for sess_abcdefghijklmnopqrstuv'
        );
        $this->assertStringContainsString('<url>', $normalized);
        $this->assertStringContainsString('sess_<id>', $normalized);
        $this->assertStringNotContainsString('example.com', $normalized);
    }

    public function testNormalizeTrimsTrailingPunctuationAndLeadingMarkers(): void
    {
        $this->assertSame('something broke', $this->fingerprinter->normalizeMessage('> Something   broke...'));
    }

    public function testJsFileReducedToEmptyBehavesLikeNoFile(): void
    {
        $this->assertSame(
            $this->fingerprinter->fingerprint('js', 'Error', 'boom', null),
            $this->fingerprinter->fingerprint('js', 'Error', 'boom', '?only=query')
        );
    }

    public function testPhpFileLineSuffixAndCaseAreIgnored(): void
    {
        $this->assertSame(
            $this->fingerprinter->fingerprint('php', 'E', 'boom', '/x/A.php'),
            $this->fingerprinter->fingerprint('php', 'E', 'boom', '/X/a.php:12:5')
        );
    }

    public function testJsFileWithoutDirectoryKeepsName(): void
    {
        $this->assertNotSame(
            $this->fingerprinter->fingerprint('js', 'Error', 'boom', 'a.js'),
            $this->fingerprinter->fingerprint('js', 'Error', 'boom', 'b.js')
        );
    }

    public function testLineNumberDoesNotSplitGroups(): void
    {
        $this->assertSame(
            $this->fingerprinter->fingerprint('php', 'E', 'boom', '/x/A.php', 10),
            $this->fingerprinter->fingerprint('php', 'E', 'boom', '/x/A.php', 99)
        );
    }
}
