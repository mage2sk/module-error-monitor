<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Logger;

use Panth\ErrorMonitor\Logger\DbHandler;
use PHPUnit\Framework\TestCase;

class DbHandlerTraceArgumentsTest extends TestCase
{
    public function testArgumentsAreRemovedFromEveryFrame(): void
    {
        $stack = "#0 /var/www/html/app/code/Foo/Bar.php(12): Foo\Bar->login('admin', 'secret-pass...')\n"
            . "#1 [internal function]: Foo\Bar->{closure}(Object(Foo\Baz), Array)\n"
            . "#2 /var/www/html/vendor/a/b.php(7): call_user_func_array(Object(Closure), Array)\n"
            . "#3 {main}";

        $expected = "#0 /var/www/html/app/code/Foo/Bar.php(12): Foo\Bar->login()\n"
            . "#1 [internal function]: Foo\Bar->{closure}()\n"
            . "#2 /var/www/html/vendor/a/b.php(7): call_user_func_array()\n"
            . "#3 {main}";

        $this->assertSame($expected, $this->strip($stack));
    }

    public function testTraceWithoutArgumentsIsUnchanged(): void
    {
        $stack = "#0 /a/b/c/Foo.php(42): Bar->baz()\n#1 {main}";
        $this->assertSame($stack, $this->strip($stack));
    }

    public function testRealExceptionTraceKeepsNoArgumentValues(): void
    {
        $exception = $this->throwWith('top-secret-value');
        $stripped = $this->strip($exception->getTraceAsString());
        $this->assertStringNotContainsString('top-secret', $stripped);
        $this->assertStringContainsString('->throwWith()', $stripped);
    }

    private function throwWith(string $secret): \Throwable
    {
        try {
            throw new \RuntimeException('failure ' . strlen($secret));
        } catch (\RuntimeException $e) {
            return $e;
        }
    }

    private function strip(string $stack): string
    {
        $r = new \ReflectionClass(DbHandler::class);
        $handler = $r->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(DbHandler::class, 'stripTraceArguments');
        $m->setAccessible(true);
        return $m->invoke($handler, $stack);
    }
}
