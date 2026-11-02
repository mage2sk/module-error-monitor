<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Panth\ErrorMonitor\Service\PathMapper;
use PHPUnit\Framework\TestCase;

class PathMapperTest extends TestCase
{
    public function testPreprocessedPathsMapToSource(): void
    {
        $mapper = new PathMapper();
        $this->assertSame(
            'vendor/magento/module-review/view/frontend/templates/form.phtml',
            $mapper->toSource('var/view_preprocessed/pub/static/vendor/magento/module-review/view/frontend/templates/form.phtml')
        );
        $this->assertSame(
            '/var/www/html/app/code/Acme/Foo/view/frontend/templates/x.phtml',
            $mapper->toSource('/var/www/html/var/view_preprocessed/pub/static/app/code/Acme/Foo/view/frontend/templates/x.phtml')
        );
        $this->assertSame('/var/www/html/vendor/a.php', $mapper->toSource('/var/www/html/vendor/a.php'));
        $this->assertNull($mapper->toSource(null));
        $this->assertTrue($mapper->isPreprocessed('var/view_preprocessed/pub/static/x.phtml'));
        $this->assertFalse($mapper->isPreprocessed('pub/static/x.js'));
    }

    public function testLineReliability(): void
    {
        $mapper = new PathMapper();
        $this->assertTrue($mapper->isLineUnreliable('var/view_preprocessed/pub/static/x.phtml', 12));
        $this->assertTrue($mapper->isLineUnreliable('https://shop.test/static/a/customer-data.min.js?v=1:1:2', 1));
        $this->assertTrue($mapper->isLineUnreliable('vendor/magento/module-review/view/frontend/templates/form.phtml', 1));
        $this->assertFalse($mapper->isLineUnreliable('vendor/magento/module-review/view/frontend/templates/form.phtml', 40));
        $this->assertFalse($mapper->isLineUnreliable('app/code/A/B/Model/C.php', 1));
        $this->assertFalse($mapper->isLineUnreliable(null, 1));
    }
}
