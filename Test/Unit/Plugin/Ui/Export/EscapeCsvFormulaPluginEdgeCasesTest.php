<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Plugin\Ui\Export;

use Magento\Framework\App\RequestInterface;
use Magento\Ui\Model\Export\MetadataProvider;
use Panth\ErrorMonitor\Plugin\Ui\Export\EscapeCsvFormulaPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EscapeCsvFormulaPluginEdgeCasesTest extends TestCase
{
    private function plugin(?string $namespace = null, ?string $action = null): EscapeCsvFormulaPlugin
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn ($key) => $key === 'namespace' ? $namespace : null
        );
        $request->method('getActionName')->willReturn((string)$action);
        return new EscapeCsvFormulaPlugin($request);
    }

    public static function escapeProvider(): array
    {
        return [
            'equals' => ['=SUM(A1)', "'=SUM(A1)"],
            'plus' => ['+cmd', "'+cmd"],
            'minus text' => ['-abc', "'-abc"],
            'at' => ['@foo', "'@foo"],
            'tab' => ["\tlead", "'\tlead"],
            'carriage return' => ["\rlead", "'\rlead"],
            'negative number' => ['-12.5', '-12.5'],
            'positive number' => ['+3', '+3'],
            'plain' => ['hello', 'hello'],
            'empty' => ['', ''],
            'formula later in string' => ['a=b', 'a=b'],
        ];
    }

    #[DataProvider('escapeProvider')]
    public function testEscape(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->plugin()->escape($input));
    }

    public function testNonStringsPassThrough(): void
    {
        $plugin = $this->plugin();
        $this->assertSame(5, $plugin->escape(5));
        $this->assertNull($plugin->escape(null));
        $this->assertSame(['=x'], $plugin->escape(['=x']));
    }

    public function testActionNameIsCaseInsensitiveAndKeysPreserved(): void
    {
        $plugin = $this->plugin(EscapeCsvFormulaPlugin::LISTING_NAMESPACE, 'GridToCsv');
        $result = $plugin->afterGetRowData(
            $this->createStub(MetadataProvider::class),
            ['message' => '=1+1', 'count' => 3, 'file' => '/a.php']
        );
        $this->assertSame(['message' => "'=1+1", 'count' => 3, 'file' => '/a.php'], $result);
    }

    public function testMissingNamespaceLeavesRowUntouched(): void
    {
        $row = ['message' => '=1+1'];
        $this->assertSame($row, $this->plugin(null, 'gridToCsv')->afterGetRowData(
            $this->createStub(MetadataProvider::class),
            $row
        ));
    }
}
