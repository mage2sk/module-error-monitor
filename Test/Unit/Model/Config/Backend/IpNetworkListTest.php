<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Panth\ErrorMonitor\Model\Config\Backend\IpNetworkList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IpNetworkListTest extends TestCase
{
    private array $warnings = [];

    private function model(string $value, array $fieldConfig = ['label' => 'IPs / Networks']): IpNetworkList
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(EventManager::class));
        $context->method('getLogger')->willReturn($this->createStub(LoggerInterface::class));
        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addWarningMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->warnings[] = (string)$message;
            return $messages;
        });
        $model = new IpNetworkList(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class),
            $messages
        );
        $model->setData('field_config', $fieldConfig);
        $model->setPath('panth_errormonitor/filters/ip_networks');
        $model->setValue($value);
        return $model;
    }

    public function testValidListWithCommentsIsSavedWithoutWarning(): void
    {
        $model = $this->model("# Baidu\r\n  116.179.0.0/16  # crawler\r\n\r\n2600:3c01::/32\n");
        $model->beforeSave();
        $this->assertSame("# Baidu\n116.179.0.0/16  # crawler\n2600:3c01::/32", $model->getValue());
        $this->assertSame([], $this->warnings);
    }

    public function testInvalidLinesWarnButTheSaveSucceeds(): void
    {
        $model = $this->model("116.179.0.0/16\nbaidu.com\n10.0.0.0/33\n220.181.0.0/16");
        $model->beforeSave();
        $this->assertSame("116.179.0.0/16\nbaidu.com\n10.0.0.0/33\n220.181.0.0/16", $model->getValue());
        $this->assertSame(
            ['"IPs / Networks": 2 line(s) are not an IPv4/IPv6 address or CIDR range and will be skipped:'
                . ' baidu.com, 10.0.0.0/33'],
            $this->warnings
        );
    }

    public function testLongInvalidListIsShortenedAndPathIsUsedWithoutLabel(): void
    {
        $model = $this->model(implode("\n", array_map(static fn ($i) => 'bad' . $i, range(1, 8))), []);
        $model->beforeSave();
        $this->assertCount(1, $this->warnings);
        $this->assertStringStartsWith('"panth_errormonitor/filters/ip_networks": 8 line(s)', $this->warnings[0]);
        $this->assertStringEndsWith('bad5, ...', $this->warnings[0]);
    }

    public function testEmptyValueIsAllowed(): void
    {
        $model = $this->model('');
        $model->beforeSave();
        $this->assertSame('', $model->getValue());
        $this->assertSame([], $this->warnings);
    }
}
