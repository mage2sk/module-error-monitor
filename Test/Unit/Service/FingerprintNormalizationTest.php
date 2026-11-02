<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Panth\ErrorMonitor\Service\Fingerprinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FingerprintNormalizationTest extends TestCase
{
    private Fingerprinter $fingerprinter;

    protected function setUp(): void
    {
        $this->fingerprinter = new Fingerprinter();
    }

    public function testUncaughtPrefixDoesNotSplitGroups(): void
    {
        $file = 'https://shop.test/static/version1785394171/frontend/Hyva/default/en_US/Magento_Customer/js/customer-data.min.js';
        $a = $this->fingerprinter->fingerprint('js', 'Error', 'Uncaught Error: [object Object]', $file, 1);
        $b = $this->fingerprinter->fingerprint('js', 'Error', 'Error: [object Object]', $file, 1);
        $this->assertSame($a, $b);
        $this->assertSame(
            $this->fingerprinter->normalizeMessage('Error: [object Object]'),
            $this->fingerprinter->normalizeMessage('Uncaught Error: [object Object]')
        );
    }

    public static function undefinedPhrasings(): array
    {
        return [
            'chrome' => ["Uncaught TypeError: Cannot read properties of undefined (reading 'remove')"],
            'chrome without prefix' => ["Cannot read properties of undefined (reading 'remove')"],
            'old chrome' => ["TypeError: Cannot read property 'remove' of undefined"],
            'safari' => ["TypeError: undefined is not an object (evaluating 'storage.remove')"],
            'firefox current' => ['TypeError: can\'t access property "remove", storage is undefined'],
            'firefox short' => ['TypeError: can\'t access property "remove" of undefined'],
            'firefox legacy' => ['TypeError: storage.remove is undefined'],
        ];
    }

    /**
     * @dataProvider undefinedPhrasings
     */
    #[DataProvider('undefinedPhrasings')]
    public function testBrowserPhrasingsCanonicalise(string $message): void
    {
        $this->assertSame(
            "typeerror: cannot read 'remove' of undefined",
            $this->fingerprinter->normalizeMessage($message)
        );
    }

    public function testChromeAndSafariGroupTogetherAcrossFiles(): void
    {
        $chrome = $this->fingerprinter->fingerprint(
            'js',
            'TypeError',
            "Uncaught TypeError: Cannot read properties of undefined (reading 'remove')",
            'https://shop.test/static/version1785394171/frontend/V/t/en_US/Magento_Customer/js/customer-data.min.js',
            1
        );
        $safari = $this->fingerprinter->fingerprint(
            'js',
            'TypeError',
            "undefined is not an object (evaluating 'storage.remove')",
            'https://shop.test/static/frontend/V/t/en_US/js/jquery.storageapi.min.js',
            2
        );
        $this->assertSame($chrome, $safari);
    }

    public function testNullVariantsCanonicaliseSeparately(): void
    {
        $chrome = $this->fingerprinter->normalizeMessage("Cannot read properties of null (reading 'style')");
        $safari = $this->fingerprinter->normalizeMessage("TypeError: null is not an object (evaluating 'el.style')");
        $firefox = $this->fingerprinter->normalizeMessage('TypeError: can\'t access property "style", el is null');
        $this->assertSame("typeerror: cannot read 'style' of null", $chrome);
        $this->assertSame($chrome, $safari);
        $this->assertSame($chrome, $firefox);
        $this->assertNotSame(
            $chrome,
            $this->fingerprinter->normalizeMessage("Cannot read properties of undefined (reading 'style')")
        );
    }

    public function testDifferentPropertiesStayDistinct(): void
    {
        $this->assertNotSame(
            $this->fingerprinter->normalizeMessage("Cannot read properties of undefined (reading 'remove')"),
            $this->fingerprinter->normalizeMessage("Cannot read properties of undefined (reading 'get')")
        );
    }

    public function testPlainIsUndefinedNeedsTypeErrorPrefix(): void
    {
        $this->assertSame('value is undefined', $this->fingerprinter->normalizeMessage('value is undefined'));
    }

    public function testStaticDeployVersionDoesNotSplitGroups(): void
    {
        $message = 'Unexpected token in customer data';
        $files = [
            'https://shop.test/static/version1785394171/frontend/Vendor/theme/en_US/Magento_Customer/js/customer-data.min.js',
            'https://shop.test/static/version1793000663/frontend/Vendor/theme/en_US/Magento_Customer/js/customer-data.min.js',
            'https://shop.test/static/frontend/Vendor/theme/en_US/Magento_Customer/js/customer-data.min.js?v=3:1:2345',
        ];
        $prints = array_map(
            fn (string $file): string => $this->fingerprinter->fingerprint('js', 'SyntaxError', $message, $file, 1),
            $files
        );
        $this->assertCount(1, array_unique($prints));

        $php = array_map(
            fn (string $file): string => $this->fingerprinter->fingerprint('php', 'Exception', $message, $file, 1),
            ['/static/version1785394171/frontend/a/b/en_US/x.js', '/static/version1793000663/frontend/a/b/en_US/x.js']
        );
        $this->assertCount(1, array_unique($php));
    }

    public function testVersionQueryAndLineColumnInMessageAreStripped(): void
    {
        $a = $this->fingerprinter->normalizeMessage(
            'Script failed at /static/version1785394171/frontend/V/t/en_US/js/app.min.js?v=1:1:23456'
        );
        $b = $this->fingerprinter->normalizeMessage(
            'Script failed at /static/version1793000663/frontend/V/t/en_US/js/app.min.js:1:999'
        );
        $this->assertSame($a, $b);
        $this->assertSame(
            'failed in app.min.js',
            $this->fingerprinter->canonicalizeBrowserMessage('failed in app.min.js:1:4567')
        );
    }

    public function testPreprocessedTemplatePathGroupsWithSourcePath(): void
    {
        $message = 'Invalid argument supplied';
        $preprocessed = $this->fingerprinter->fingerprint(
            'php',
            'Exception',
            $message,
            'var/view_preprocessed/pub/static/vendor/magento/module-review/view/frontend/templates/form.phtml',
            1
        );
        $source = $this->fingerprinter->fingerprint(
            'php',
            'Exception',
            $message,
            'vendor/magento/module-review/view/frontend/templates/form.phtml',
            1
        );
        $this->assertSame($preprocessed, $source);
    }

    public function testCanonicalTypeErrorIsFileAgnostic(): void
    {
        $this->assertTrue(
            $this->fingerprinter->isFrameworkGenericJs("typeerror: cannot read 'remove' of undefined")
        );
    }
}
