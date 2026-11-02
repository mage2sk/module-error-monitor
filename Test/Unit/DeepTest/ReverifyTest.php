<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\DeepTest;

use Panth\ErrorMonitor\Service\CliContext;
use Panth\ErrorMonitor\Service\NoiseFilter;
use PHPUnit\Framework\TestCase;

/**
 * Independent re-verification probes (release 1.7.0). Each test documents observed behaviour.
 */
class ReverifyTest extends TestCase
{
    private function rules(): array
    {
        return NoiseFilter::parseRules(NoiseFilter::DEFAULT_RULES);
    }

    /** B4: storage rules are meant to apply only to customer-data / js.storage / storageapi sources. */
    public function testSecurityErrorFromOwnCodeIsNotHidden(): void
    {
        $own = 'https://shop.test/static/frontend/Vendor/theme/en_US/Vendor_Checkout/js/save-draft.js';
        $msg = "SecurityError: Failed to read the 'localStorage' property from 'Window': Access is denied";
        $this->assertFalse(
            NoiseFilter::matchRules($this->rules(), $msg, $own, "at save (" . $own . ":10:5)"),
            'global SecurityError rule hides a storage SecurityError thrown by the store\'s own module'
        );
    }

    /** The global rule's bare "storage" alternative also matches unrelated SecurityErrors. */
    public function testUnrelatedSecurityErrorMentioningStorageIsNotHidden(): void
    {
        $msg = "SecurityError: Failed to construct 'Worker': Script at 'https://storage.googleapis.com/x/w.js'"
            . " cannot be accessed from origin 'https://shop.test'.";
        $this->assertFalse(
            NoiseFilter::matchRules($this->rules(), $msg, 'https://shop.test/static/app.js', 'at boot (app.js:1:1)'),
            'cross-origin Worker bug hidden because the URL contains "storage"'
        );
    }

    public function testStorageErrorInsideCustomerDataIsHidden(): void
    {
        $cd = 'https://shop.test/static/version1/frontend/Magento/luma/en_US/Magento_Customer/js/customer-data.js';
        $this->assertTrue(NoiseFilter::matchRules(
            $this->rules(),
            "SecurityError: Failed to read the 'localStorage' property from 'Window'",
            $cd,
            ''
        ));
    }

    /** S4: config:set with space-separated --scope / --scope-code values. */
    public function testConfigSetWithSpaceSeparatedScopeMasksValue(): void
    {
        $line = (new CliContext())->sanitizeArgv([
            'bin/magento', 'config:set', '--scope', 'websites', '--scope-code', 'base',
            'carriers/ups/password', 'Hunter2pw',
        ]);
        $this->assertStringNotContainsString('Hunter2pw', $line, $line);
    }

    public function testConfigSensitiveSetWithSpaceSeparatedScopeMasksValue(): void
    {
        $line = (new CliContext())->sanitizeArgv([
            'bin/magento', 'config:sensitive:set', '--scope', 'websites', '--scope-code', 'base',
            'payment/x/merchant_id', 'Acme42pw',
        ]);
        $this->assertStringNotContainsString('Acme42pw', $line, $line);
    }

    public function testConfigSetEqualsFormMasksValue(): void
    {
        $line = (new CliContext())->sanitizeArgv([
            'bin/magento', 'config:set', '--scope=websites', '--scope-code=base', 'carriers/ups/password', 'Hunter2pw',
        ]);
        $this->assertStringNotContainsString('Hunter2pw', $line, $line);
    }

    public function testCommonSecretOptionsAreMasked(): void
    {
        $c = new CliContext();
        foreach ([
            ['bin/magento', 'admin:user:create', '--admin-password', 'S3cretpw'],
            ['bin/magento', 'setup:install', '--db-password=S3cretpw'],
            ['bin/magento', 'setup:install', '--search-engine=opensearch', '--opensearch-password', 'S3cretpw'],
            ['mysql', '-uroot', '-pS3cretpw'],
            ['bin/magento', 'x', 'https://user:S3cretpw@host/'],
            ['bin/magento', 'admin:user:create', '--admin-email', 'S3cretpw@x.io'],
        ] as $argv) {
            $line = $c->sanitizeArgv($argv);
            $this->assertStringNotContainsString('S3cretpw', $line, $line);
        }
    }
}
