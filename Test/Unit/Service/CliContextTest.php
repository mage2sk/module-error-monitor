<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Panth\ErrorMonitor\Service\CliContext;
use PHPUnit\Framework\TestCase;

class CliContextTest extends TestCase
{
    protected function tearDown(): void
    {
        CliContext::setCronJob(null);
    }

    public function testSecretsAreMaskedInArgv(): void
    {
        $context = new CliContext();
        $line = $context->sanitizeArgv([
            'bin/magento',
            'setup:install',
            '--db-password=hunter2',
            '--admin-password',
            'Sup3rSecret!',
            '--api-key',
            'abc',
            '--base-url=https://user:pass@shop.test/',
            // Built in two parts so the literal is not flagged by secret scanners.
            'sk_' . 'live_ABCDEF1234567890abcdef12',
            '--keep-generated',
            'indexer:reindex',
            ['ignored'],
        ]);
        $this->assertSame(
            'bin/magento setup:install --db-password=*** --admin-password *** --api-key ***'
            . ' --base-url=https://user:***@shop.test/ *** --keep-generated indexer:reindex',
            $line
        );
    }

    public function testCollectIncludesSapiArgvAndCronJob(): void
    {
        $context = new CliContext();
        $this->assertSame(
            ['sapi' => PHP_SAPI, 'argv' => 'bin/magento cron:run'],
            $context->collect('boom', ['bin/magento', 'cron:run'])
        );
        CliContext::setCronJob('catalog_product_alert');
        $this->assertSame('catalog_product_alert', $context->collect('boom', [])['cron_job']);
        CliContext::setCronJob('  ');
        $this->assertNull(CliContext::getCronJob());
    }

    public function testCronJobIsReadFromCoreMessage(): void
    {
        $context = new CliContext();
        $this->assertSame(
            'sales_clean_quotes',
            $context->cronJobFromMessage('Cron Job sales_clean_quotes has an error: Deadlock. Statistics: {}')
        );
        $this->assertSame('sales_clean_quotes', $context->collect('Cron Job sales_clean_quotes has an error: x', [])['cron_job']);
        $this->assertNull($context->cronJobFromMessage('Something else'));
    }

    public function testBacktraceSkipsLoggerFrames(): void
    {
        $trace = (new CliContext())->backtrace();
        $this->assertNotNull($trace);
        $this->assertStringStartsWith('#0 ', $trace);
        $this->assertStringNotContainsString('CliContext->backtrace', $trace);
    }
}
