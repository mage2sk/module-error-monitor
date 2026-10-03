<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Plugin\Ui\Export;

use Magento\Framework\App\RequestInterface;
use Magento\Ui\Model\Export\MetadataProvider;
use Panth\ErrorMonitor\Plugin\Ui\Export\EscapeCsvFormulaPlugin;
use PHPUnit\Framework\TestCase;

class EscapeCsvFormulaPluginTest extends TestCase
{
    private const ROW = ['7', '=HYPERLINK("http://x")', '+1+2', '-cmd', '@SUM(A1)', "\tx", 'plain', '-5', '', null, 3];

    public function testCsvExportOfErrorGridIsEscaped(): void
    {
        $result = $this->plugin(EscapeCsvFormulaPlugin::LISTING_NAMESPACE, 'gridToCsv')
            ->afterGetRowData($this->createStub(MetadataProvider::class), self::ROW);

        $this->assertSame(
            ['7', '\'=HYPERLINK("http://x")', '\'+1+2', '\'-cmd', '\'@SUM(A1)', "'\tx", 'plain', '-5', '', null, 3],
            $result
        );
    }

    public function testOtherListingsAreUntouched(): void
    {
        $result = $this->plugin('sales_order_grid', 'gridToCsv')
            ->afterGetRowData($this->createStub(MetadataProvider::class), self::ROW);
        $this->assertSame(self::ROW, $result);
    }

    public function testXmlExportIsUntouched(): void
    {
        $result = $this->plugin(EscapeCsvFormulaPlugin::LISTING_NAMESPACE, 'gridToXml')
            ->afterGetRowData($this->createStub(MetadataProvider::class), self::ROW);
        $this->assertSame(self::ROW, $result);
    }

    private function plugin(string $namespace, string $action): EscapeCsvFormulaPlugin
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn($namespace);
        $request->method('getActionName')->willReturn($action);
        return new EscapeCsvFormulaPlugin($request);
    }
}
