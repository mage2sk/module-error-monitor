<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\EmailNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmailNotifierTest extends TestCase
{
    private array $calls = [];

    private array $recipients = ['a@example.com', 'b@example.com'];

    private ?\Throwable $sendFailure = null;

    private array $warnings = [];

    private function notifier(StoreManagerInterface $storeManager): EmailNotifier
    {
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('sendMessage')->willReturnCallback(function (): void {
            if ($this->sendFailure !== null) {
                throw $this->sendFailure;
            }
            $this->calls[] = ['sendMessage'];
        });

        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnCallback(function (...$args) use ($builder, $method) {
                $this->calls[] = [$method, $args[0]];
                return $builder;
            });
        }
        $builder->method('getTransport')->willReturn($transport);

        $emulation = $this->createStub(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(function (...$args): void {
            $this->calls[] = ['start', $args];
        });
        $emulation->method('stopEnvironmentEmulation')->willReturnCallback(function () use ($emulation) {
            $this->calls[] = ['stop'];
            return $emulation;
        });

        $config = $this->createStub(Config::class);
        $config->method('getEmailRecipients')->willReturnCallback(fn () => $this->recipients);
        $config->method('getEmailSender')->willReturn('support');

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = $message;
        });

        return new EmailNotifier($builder, $storeManager, $emulation, $config, $logger);
    }

    private function store(int $id, string $name): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getName')->willReturn($name);
        return $store;
    }

    private function storeManager(?Store $default, array $stores = []): StoreManagerInterface
    {
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getDefaultStoreView')->willReturn($default);
        $manager->method('getStores')->willReturn($stores);
        return $manager;
    }

    private function call(string $name): array
    {
        foreach ($this->calls as $call) {
            if ($call[0] === $name) {
                return $call;
            }
        }
        $this->fail('Call ' . $name . ' not recorded');
    }

    public function testNothingToSend(): void
    {
        $notifier = $this->notifier($this->storeManager($this->store(1, 'Shop')));
        $this->assertFalse($notifier->send([]));
        $this->assertFalse($notifier->send([new DataObject(['group_id' => 0])]));

        $this->recipients = [];
        $this->assertFalse($notifier->send([new DataObject(['group_id' => 4])]));
        $this->assertSame([], $this->calls);
    }

    public function testSendsSummaryToAllRecipients(): void
    {
        $notifier = $this->notifier($this->storeManager($this->store(3, 'Main Shop')));
        $result = $notifier->send([
            new DataObject(['group_id' => 5]),
            new DataObject(['group_id' => 0]),
            new DataObject(['group_id' => '8']),
        ]);

        $this->assertTrue($result);
        $this->assertSame(['start', [3, 'frontend', true]], $this->call('start'));
        $this->assertSame('panth_errormonitor_alert_template', $this->call('setTemplateIdentifier')[1]);
        $this->assertSame(['area' => 'frontend', 'store' => 3], $this->call('setTemplateOptions')[1]);
        $this->assertSame([
            'subject' => '[Main Shop] Error Monitor: 2 error groups',
            'site_name' => 'Main Shop',
            'error_count' => 2,
            'group_ids' => '5,8',
        ], $this->call('setTemplateVars')[1]);
        $this->assertSame('support', $this->call('setFromByScope')[1]);
        $addTo = array_values(array_filter($this->calls, static fn ($c) => $c[0] === 'addTo'));
        $this->assertSame([['addTo', 'a@example.com'], ['addTo', 'b@example.com']], $addTo);
        $this->assertSame(['sendMessage'], $this->calls[count($this->calls) - 2]);
        $this->assertSame(['stop'], end($this->calls));
    }

    public function testSingularSubjectAndFirstStoreFallback(): void
    {
        $notifier = $this->notifier($this->storeManager(null, [$this->store(6, 'Other')]));
        $this->assertTrue($notifier->send([new DataObject(['group_id' => 1])]));

        $vars = $this->call('setTemplateVars')[1];
        $this->assertSame('[Magento] Error Monitor: 1 error group', $vars['subject']);
        $this->assertSame(6, $this->call('setTemplateOptions')[1]['store']);
    }

    public function testDefaultStoreIdWhenStoresUnavailable(): void
    {
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getDefaultStoreView')->willThrowException(new \RuntimeException('no stores'));
        $notifier = $this->notifier($manager);

        $this->assertTrue($notifier->send([new DataObject(['group_id' => 1])]));
        $this->assertSame(0, $this->call('setTemplateOptions')[1]['store']);
        $this->assertSame('Magento', $this->call('setTemplateVars')[1]['site_name']);
    }

    public function testEmptyStoreListFallsBackToDefaultId(): void
    {
        $notifier = $this->notifier($this->storeManager(null, []));
        $this->assertTrue($notifier->send([new DataObject(['group_id' => 1])]));
        $this->assertSame(0, $this->call('setTemplateOptions')[1]['store']);
    }

    public function testTransportFailureIsLoggedAndEmulationStopped(): void
    {
        $this->sendFailure = new \RuntimeException('smtp down');
        $notifier = $this->notifier($this->storeManager($this->store(1, 'Shop')));

        $this->assertFalse($notifier->send([new DataObject(['group_id' => 2])]));
        $this->assertSame(['[PanthErrorMonitor] email send failed: smtp down'], $this->warnings);
        $this->assertSame(['stop'], end($this->calls));
    }
}
