<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\DeepTest;

use Panth\ErrorMonitor\Service\Fingerprinter;
use PHPUnit\Framework\TestCase;

/**
 * Deep test CHUNK 2 - fingerprint normalisation (owner item #5).
 *
 * Tests named testBug* document a defect: they fail on 1.7.0 as shipped and
 * should pass once the defect is fixed. Tests named testDesign* pin current
 * behaviour that is a deliberate trade-off (they pass) so a fixer notices if it
 * changes.
 */
class FingerprintDeepTest extends TestCase
{
    private const CD = 'https://s.test/static/version1785394171/frontend/Hyva/default/en_US/Magento_Customer/js/customer-data.js';

    private Fingerprinter $fp;

    protected function setUp(): void
    {
        $this->fp = new Fingerprinter();
    }

    private function same(array $a, array $b, string $msg = ''): void
    {
        $this->assertSame($this->fp->fingerprint(...$a), $this->fp->fingerprint(...$b), $msg);
    }

    private function differ(array $a, array $b, string $msg = ''): void
    {
        $this->assertNotSame($this->fp->fingerprint(...$a), $this->fp->fingerprint(...$b), $msg);
    }

    // ---------- prefixes ----------

    public function testUncaughtVariantsGroupTogether(): void
    {
        $base = ['js', 'Error', 'Error: X happened', self::CD, 10];
        foreach ([
            'Uncaught Error: X happened',
            '  Uncaught Error: X happened',
            "Uncaught\nError: X happened",
            'Uncaught (in promise) Error: X happened',
            'UNCAUGHT Error: X happened',
        ] as $m) {
            $this->same($base, ['js', 'Error', $m, self::CD, 10], $m);
        }
        // Type mined from message when the collector sends a generic/empty type.
        $this->same(['js', '', 'Uncaught Error: X happened', self::CD, 1], ['js', '', 'Error: X happened', self::CD, 1]);
        // "Uncaught X" (no type word) equals the bare "X" message.
        $this->same(['js', '', 'Uncaught X happened', self::CD, 1], ['js', '', 'X happened', self::CD, 1]);
    }

    public function testUncaughtInsideMessageIsNotStripped(): void
    {
        $this->assertSame('foo: uncaught error', $this->fp->normalizeMessage('Foo: Uncaught Error'));
    }

    // ---------- browser TypeError phrasings ----------

    public function testUndefinedPropertyPhrasingsAllCanonical(): void
    {
        $expected = "typeerror: cannot read 'remove' of undefined";
        foreach ([
            "Uncaught TypeError: Cannot read properties of undefined (reading 'remove')",
            "Cannot read properties of undefined (reading 'remove')",
            "TypeError: Cannot read property 'remove' of undefined",
            "TypeError: undefined is not an object (evaluating 'storage.remove')",
            "TypeError: undefined is not an object (evaluating 'a[0].remove')",
            'TypeError: can\'t access property "remove", storage is undefined',
            "TypeError: can\u{2019}t access property \"remove\", storage is undefined",
            'TypeError: can\'t access property "remove" of undefined',
            'TypeError: storage.remove is undefined',
        ] as $m) {
            $this->assertSame($expected, $this->fp->normalizeMessage($m), $m);
        }
        $this->assertSame(
            "typeerror: cannot read 'remove' of null",
            $this->fp->normalizeMessage("TypeError: null is not an object (evaluating 'storage.remove')")
        );
    }

    public function testNullAndUndefinedStaySeparate(): void
    {
        $this->assertNotSame(
            $this->fp->normalizeMessage("TypeError: Cannot read properties of null (reading 'remove')"),
            $this->fp->normalizeMessage("TypeError: Cannot read properties of undefined (reading 'remove')")
        );
    }

    public function testDifferentPropertyNamesDoNotMerge(): void
    {
        $this->differ(
            ['js', 'TypeError', "TypeError: Cannot read properties of undefined (reading 'remove')", self::CD, 1],
            ['js', 'TypeError', "TypeError: Cannot read properties of undefined (reading 'add')", self::CD, 1]
        );
        $this->differ(
            ['js', 'TypeError', "TypeError: Cannot read properties of undefined (reading '_x')", self::CD, 1],
            ['js', 'TypeError', "TypeError: Cannot read properties of undefined (reading '_y')", self::CD, 1]
        );
    }

    /**
     * BUG: property names containing "$" (Alpine/Hyva: $el, $refs, $store,
     * $dispatch) are collapsed to "<v>" by collapseQuotedValues(), because "$"
     * is not in IDENTIFIER_TOKEN. All "$" properties end up in ONE group.
     * Input: "...(reading '$el')" and "...(reading '$refs')"
     * Output (both): "typeerror: cannot read <v> of undefined"
     */
    public function testBugDollarPropertiesMergeIntoOneGroup(): void
    {
        $el = $this->fp->normalizeMessage("TypeError: Cannot read properties of undefined (reading '\$el')");
        $refs = $this->fp->normalizeMessage("TypeError: Cannot read properties of undefined (reading '\$refs')");
        $this->assertNotSame($el, $refs, 'distinct $-properties must not merge; got ' . $el);
    }

    public function testDollarPropertyChromeAndSafariAgree(): void
    {
        $this->assertSame(
            $this->fp->normalizeMessage("TypeError: Cannot read properties of undefined (reading '\$refs')"),
            $this->fp->normalizeMessage("TypeError: undefined is not an object (evaluating 'this.\$refs')")
        );
    }

    /**
     * BUG (minor): Chrome "(reading 'data-id')" canonicalises to 'dataid', but
     * Safari "(evaluating 'x[\"data-id\"]')" is not matched by the Safari regex
     * (prop may not contain quotes) and stays "typeerror: undefined is not an
     * object (evaluating <v>)" -> still two groups for one bug.
     */
    public function testBugBracketKeyWithDashChromeVsSafari(): void
    {
        $this->assertSame(
            $this->fp->normalizeMessage("TypeError: Cannot read properties of undefined (reading 'data-id')"),
            $this->fp->normalizeMessage("TypeError: undefined is not an object (evaluating 'x[\"data-id\"]')")
        );
    }

    public function testDesignNumericIndexPropertiesCollapse(): void
    {
        // '0' and '1' both become "<v>": index reads on undefined merge. Accepted.
        $this->assertSame(
            $this->fp->normalizeMessage("TypeError: Cannot read properties of undefined (reading '0')"),
            $this->fp->normalizeMessage("TypeError: Cannot read properties of undefined (reading '1')")
        );
    }

    public function testDesignNotAFunctionPhrasingsNotUnified(): void
    {
        // Out of owner scope: Chrome/Firefox vs Safari "not a function" stay separate.
        $this->assertNotSame(
            $this->fp->normalizeMessage('TypeError: x.y is not a function'),
            $this->fp->normalizeMessage("TypeError: undefined is not a function (near '...x.y()...')")
        );
        // ... but different callee names must stay distinct.
        $this->assertNotSame(
            $this->fp->normalizeMessage('TypeError: storage.remove is not a function'),
            $this->fp->normalizeMessage('TypeError: cart.remove is not a function')
        );
    }

    public function testDesignOldEdgePhrasingNotCanonical(): void
    {
        $this->assertSame(
            "typeerror: unable to get property 'remove' of undefined or null reference",
            $this->fp->normalizeMessage("TypeError: Unable to get property 'remove' of undefined or null reference")
        );
    }

    // ---------- static version / query / line:col ----------

    public function testStaticVersionInFilePathIgnored(): void
    {
        $tail = 'frontend/Hyva/default/en_US/Magento_Customer/js/customer-data.js';
        $a = ['js', 'Error', 'Error: X', 'https://s.test/static/version1785394171/' . $tail, 1];
        $this->same($a, ['js', 'Error', 'Error: X', 'https://s.test/static/version1793000663/' . $tail, 1]);
        $this->same($a, ['js', 'Error', 'Error: X', 'https://s.test/static/' . $tail, 1]);
        $this->same($a, ['js', 'Error', 'Error: X', '/pub/static/' . $tail, 1]);
    }

    public function testStaticVersionInMessageIgnored(): void
    {
        $a = $this->fp->normalizeMessage('Error in /static/version1785394171/frontend/A/b/en_US/x.min.js?v=123:1:4455');
        $b = $this->fp->normalizeMessage('Error in /static/version1793000663/frontend/A/b/en_US/x.min.js:1:99');
        $c = $this->fp->normalizeMessage('Error in /static/frontend/A/b/en_US/x.min.js');
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    public function testQueryHashAndLineColInFileIgnored(): void
    {
        $base = ['js', 'Error', 'Error: X', self::CD, 1];
        $this->same($base, ['js', 'Error', 'Error: X', self::CD . '?v=123', 1]);
        $this->same($base, ['js', 'Error', 'Error: X', self::CD . '#frag', 1]);
        $this->same($base, ['js', 'Error', 'Error: X', self::CD . ':10:5', 99]);
    }

    public function testDesignMinifiedFileIsADifferentGroupThanSource(): void
    {
        // customer-data.js vs customer-data.min.js: basename differs -> separate group.
        $this->differ(
            ['js', 'Error', 'Error: X', self::CD, 1],
            ['js', 'Error', 'Error: X', str_replace('.js', '.min.js', self::CD), 1]
        );
    }

    /**
     * Design note: line numbers are dropped for NON-minified files too, both in
     * the file argument (normalizeFile strips ":l:c", $line is never used) and
     * in the message (canonicalizeBrowserMessage strips ".js:l:c"). Two
     * different bugs with the same message in one non-minified file merge.
     * 1.6.4 behaved the same (it turned ":10:5" into "<ip>"), so no regression.
     */
    public function testDesignNonMinifiedLineNumbersDropped(): void
    {
        $this->assertSame(
            $this->fp->normalizeMessage('Error in app.js:10:5'),
            $this->fp->normalizeMessage('Error in app.js:20:5')
        );
        $this->same(['js', 'Error', 'Error: X', self::CD, 10], ['js', 'Error', 'Error: X', self::CD, 500]);
        $this->same(
            ['php', 'Exception', 'Undefined index: foo', '/var/www/html/app/code/A/B/C.php', 12],
            ['php', 'Exception', 'Undefined index: foo', '/var/www/html/app/code/A/B/C.php', 99]
        );
    }

    /** BUG (minor): a hash fragment after a file name in the message is kept. */
    public function testBugHashFragmentInMessageKept(): void
    {
        $this->assertSame(
            $this->fp->normalizeMessage('Error in app.js'),
            $this->fp->normalizeMessage('Error in app.js#foo')
        );
    }

    // ---------- must NOT merge ----------

    public function testSameMessageDifferentFilesDoNotMerge(): void
    {
        $this->differ(['js', 'Error', 'Error: X', self::CD, 1], ['js', 'Error', 'Error: X', str_replace('customer-data', 'section-config', self::CD), 1]);
        $this->differ(
            ['php', 'Exception', 'Error: X', '/var/www/html/app/code/A/B/x.php', 1],
            ['php', 'Exception', 'Error: X', '/var/www/html/app/code/A/C/x.php', 1]
        );
    }

    /**
     * BUG (pre-existing since 1.6.x, major for grouping accuracy): for JS only
     * the basename is used, so the same message thrown from two different
     * modules' files with the same basename (Magento_Ui/js/view/x.js vs
     * Magento_Checkout/js/view/x.js) merges into one group.
     */
    public function testBugJsSameBasenameDifferentModulesMerge(): void
    {
        $this->differ(
            ['js', 'Error', 'Error: X', 'https://s.test/static/frontend/A/b/en_US/Magento_Ui/js/view/summary.js', 1],
            ['js', 'Error', 'Error: X', 'https://s.test/static/frontend/A/b/en_US/Magento_Checkout/js/view/summary.js', 1]
        );
    }

    public function testFrameworkGenericJsDropsFileOnPurpose(): void
    {
        $m = "TypeError: Cannot read properties of undefined (reading 'remove')";
        $this->same(['js', 'TypeError', $m, self::CD, 1], ['js', 'TypeError', $m, 'https://s.test/static/frontend/A/b/en_US/Magento_Ui/js/x.js', 1]);
    }

    public function testPhpAndJsNeverCrossMerge(): void
    {
        $this->differ(['js', 'Error', 'Error: X', '', null], ['php', 'Error', 'Error: X', '', null]);
        $this->differ(['js', 'Error', 'Error: X', 'a.js', null], ['php', 'Error', 'Error: X', 'a.js', null]);
    }

    public function testPhpDifferentFilesWithPathInMessage(): void
    {
        $this->differ(
            ['php', 'Notice', 'Notice: Undefined index: foo in /var/www/html/app/code/A/B/C.php on line 12', '/var/www/html/app/code/A/B/C.php', 12],
            ['php', 'Notice', 'Notice: Undefined index: foo in /var/www/html/app/code/A/B/D.php on line 12', '/var/www/html/app/code/A/B/D.php', 12]
        );
    }

    // ---------- odd input ----------

    public function testLongUnicodeEmptyWhitespaceInputs(): void
    {
        $long = str_repeat('abc ', 2500);
        $this->assertSame(10000, strlen($long));
        $n = $this->fp->normalizeMessage($long);
        $this->assertLessThanOrEqual(500, mb_strlen($n));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->fp->fingerprint('js', '', $long, null, null));

        $this->assertSame('ünïcødé 错误 🚀', $this->fp->normalizeMessage('Ünïcødé  错误 🚀'));
        $this->assertLessThanOrEqual(500, mb_strlen($this->fp->normalizeMessage(str_repeat('é', 40000))));

        foreach (['', '   ', "\n\r\t", "\r\n"] as $blank) {
            $this->assertSame('', $this->fp->normalizeMessage($blank));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->fp->fingerprint('php', '', $blank));
        }
        // Invalid UTF-8 must not throw and must give a valid hash.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->fp->fingerprint('js', '', "bad \xC3\x28 bytes"));
    }

    /**
     * BUG (minor, pre-existing): a single "\n" survives normalisation while
     * "\r\n" collapses to a space, so the same multi-line message from a
     * Windows/CRLF source and an LF source gives two groups.
     * "line1\nline2" -> "line1\nline2"; "line1\r\nline2" -> "line1 line2".
     */
    public function testBugCrlfAndLfDiffer(): void
    {
        $this->assertSame(
            $this->fp->normalizeMessage("line1\r\nline2"),
            $this->fp->normalizeMessage("line1\nline2")
        );
    }

    public function testVolatileIdsCollapsed(): void
    {
        $this->assertSame('order <id> not found', $this->fp->normalizeMessage('Order 000123 not found'));
        $this->assertSame(
            $this->fp->normalizeMessage('Order 000123 not found'),
            $this->fp->normalizeMessage('Order 000124 not found')
        );
        $this->assertSame(
            $this->fp->normalizeMessage('Order 12 not found'),
            $this->fp->normalizeMessage('Order 13 not found')
        );
        $this->assertSame(
            $this->fp->normalizeMessage('SKU ABC-123 missing'),
            $this->fp->normalizeMessage('SKU ABC-124 missing')
        );
    }

    // ---------- stability ----------

    public function testIdempotentNormalisation(): void
    {
        foreach ([
            "Uncaught TypeError: Cannot read properties of undefined (reading 'remove') at x.js:1:2",
            "Error: X at a.js:1:2 Order 000123 'abc def' https://x.y/z?q=1",
            'Notice: Undefined index: foo in /var/www/html/app/code/A/B/C.php on line 12',
            "TypeError: undefined is not an object (evaluating 'storage.remove')",
            '{"a": [1,2,3,4,5,6,7,8,9,10,11,12,13]}',
            'Error   with  spaces .',
        ] as $m) {
            $once = $this->fp->normalizeMessage($m);
            $this->assertSame($once, $this->fp->normalizeMessage($once), $m);
        }
    }

    /**
     * BUG (minor): normalizeMessage() trims BEFORE cutting to 500 chars, so a
     * cut that lands after a space leaves a trailing space and a second pass
     * gives a different string (not idempotent). Input: str_repeat('x y ', 3000).
     * Fix: trim after mb_substr().
     */
    public function testBugTruncationNotIdempotent(): void
    {
        $once = $this->fp->normalizeMessage(str_repeat('x y ', 3000));
        $this->assertSame($once, $this->fp->normalizeMessage($once), 'trailing char: ' . json_encode(substr($once, -2)));
    }

    public function testFingerprintFitsVarchar64Column(): void
    {
        foreach ([['js', '', ''], ['php', str_repeat('T', 500), str_repeat('m', 50000)]] as [$s, $t, $m]) {
            $this->assertSame(64, strlen($this->fp->fingerprint($s, $t, $m, str_repeat('f/', 400), 1)));
        }
    }

    // ---------- backward compatibility with 1.6.4 ----------

    /**
     * PHP hash computed with the 1.6.4 Fingerprinter (backup copy). JS file references now use the
     * module path after the static/version/theme/locale prefix instead of the basename, so JS hashes
     * change once more; panth:errormonitor:refingerprint merges the old groups.
     */
    public function testBcUnchangedForPlainMessages(): void
    {
        $this->assertSame(
            hash('sha256', 'js|error|error: x happened|magento_customer/js/customer-data.js'),
            $this->fp->fingerprint('js', 'Error', 'Error: X happened', self::CD, 10)
        );
        $this->assertSame(
            'e3530b80413606988eb8d0c3d1524b68c0e2246aea93a6b58e01037d0bf8bab7',
            $this->fp->fingerprint('php', 'Exception', 'Order 000123 not found', '/var/www/html/app/code/A/B/C.php', 10)
        );
        $this->assertSame(
            hash('sha256', 'js|referenceerror|referenceerror: foo is not defined|magento_customer/js/customer-data.js'),
            $this->fp->fingerprint('js', 'ReferenceError', 'ReferenceError: foo is not defined', self::CD, 3)
        );
    }

    /**
     * Expected change: 1.6.4 groups for "Uncaught ..." and browser TypeError
     * phrasings get a new hash, so new events open NEW groups until
     * panth:error-monitor:refingerprint is run (no data patch runs it).
     */
    public function testBcChangedForUncaughtAndTypeErrorMessages(): void
    {
        $this->assertNotSame(
            '8c7006303848e321862de3512582b704880c7f1c78a66e60d0d5f268e48a21dc',
            $this->fp->fingerprint('js', 'Error', 'Uncaught Error: X happened', self::CD, 10)
        );
        $this->assertNotSame(
            '71bfd283d7c21251db87e797f842055ace7a7c9debf322aa704207d4dfa11c78',
            $this->fp->fingerprint('js', 'TypeError', "Uncaught TypeError: Cannot read properties of undefined (reading 'remove')", self::CD, 1)
        );
    }
}
