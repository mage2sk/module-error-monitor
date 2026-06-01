<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * The stack-trace synthesis helpers are private inside DbHandler; we test
 * the contract via a tiny subclass that exposes them. This keeps the unit
 * test fast (no Magento boot) and pinned to the actual production code.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Logger;

use Panth\ErrorMonitor\Logger\DbHandler;
use PHPUnit\Framework\TestCase;

class DbHandlerStackSynthTest extends TestCase
{
    public function testSynthesizeMessageFromTraceFallsBackToFirstLineForGlobalFunctionFrame(): void
    {
        // First frame is a plain global function call (array_unique) — no
        // Class->method / Class::method — so synth can't build a clean
        // "call() at file:line" message. It falls back to the verbatim
        // first frame line so the operator still sees actionable context.
        $stack = <<<TRACE
#0 /var/www/vhosts/site/docroot/vendor/magento/framework/Validator/HTML/ConfigurableWYSIWYGValidator.php(142): array_unique(Array)
#1 /var/www/vhosts/site/docroot/vendor/magento/framework/Validator/HTML/ConfigurableWYSIWYGValidator.php(97): Magento\Framework\Validator\HTML\ConfigurableWYSIWYGValidator->validateConfigured(Object(DOMXPath))
#2 {main}
TRACE;
        $msg = $this->invokeSynth($stack);
        $this->assertStringStartsWith('#0 ', $msg);
        $this->assertStringContainsString('array_unique(Array)', $msg);
    }

    public function testSynthesizeMessageWithClassMethodFrame(): void
    {
        $stack = <<<TRACE
#0 /var/www/.../vendor/magento/framework/Model/AbstractModel.php(663): Magento\Variable\Model\Variable->beforeSave()
#1 [internal function]: foo()
TRACE;
        $msg = $this->invokeSynth($stack);
        $this->assertStringContainsString('Magento\\Variable\\Model\\Variable->beforeSave()', $msg);
        $this->assertStringContainsString('magento/framework/Model/AbstractModel.php:663', $msg);
    }

    public function testTopFrameLocationReturnsFileAndLine(): void
    {
        $stack = "#0 /a/b/c/Foo.php(42): Bar->baz()\n#1 ...";
        [$file, $line] = $this->invokeTopFrame($stack);
        $this->assertSame('/a/b/c/Foo.php', $file);
        $this->assertSame(42, $line);
    }

    public function testEmptyTraceFallbackMessage(): void
    {
        $msg = $this->invokeSynth('');
        $this->assertSame('Cron exception (see stack trace)', $msg);
    }

    private function invokeSynth(string $stack): string
    {
        $h = $this->makeReflectionHandler();
        $m = new \ReflectionMethod(DbHandler::class, 'synthesizeMessageFromTrace');
        $m->setAccessible(true);
        return $m->invoke($h, $stack);
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    private function invokeTopFrame(string $stack): array
    {
        $h = $this->makeReflectionHandler();
        $m = new \ReflectionMethod(DbHandler::class, 'topFrameLocation');
        $m->setAccessible(true);
        return $m->invoke($h, $stack);
    }

    /**
     * Build a DbHandler without invoking its constructor (no DI dependencies)
     * so we can call its private helpers in isolation.
     */
    private function makeReflectionHandler(): DbHandler
    {
        $r = new \ReflectionClass(DbHandler::class);
        return $r->newInstanceWithoutConstructor();
    }
}
