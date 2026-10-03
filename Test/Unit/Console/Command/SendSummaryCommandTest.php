<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Panth\ErrorMonitor\Console\Command\SendSummaryCommand;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\EmailNotifier;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\Collection;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SendSummaryCommandTest extends TestCase
{
    private bool $enabled = true;

    private array $recipients = ['ops@example.com'];

    private string $minSeverity = 'critical';

    private array $groups = [];

    private array $filters = [];

    private array $calls = [];

    private bool $sendResult = true;

    private array $sent = [];

    private array $areaCodes = [];

    private function tester(): CommandTester
    {
        $config = $this->createStub(Config::class);
        $config->method('isEmailEnabled')->willReturnCallback(fn () => $this->enabled);
        $config->method('getEmailRecipients')->willReturnCallback(fn () => $this->recipients);
        $config->method('getEmailMinSeverity')->willReturnCallback(fn () => $this->minSeverity);
        $config->method('getEmailMaxPerRun')->willReturn(40);

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use ($collection) {
            $this->filters[$field] = $cond;
            return $collection;
        });
        foreach (['setOrder', 'setPageSize', 'setCurPage'] as $method) {
            $collection->method($method)->willReturnCallback(function (...$args) use ($collection, $method) {
                $this->calls[] = [$method, $args];
                return $collection;
            });
        }
        $collection->method('getItems')->willReturnCallback(fn () => $this->groups);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $notifier = $this->createStub(EmailNotifier::class);
        $notifier->method('send')->willReturnCallback(function (array $groups): bool {
            $this->sent[] = $groups;
            return $this->sendResult;
        });

        $state = $this->createStub(State::class);
        $state->method('setAreaCode')->willReturnCallback(function ($code): void {
            $this->areaCodes[] = $code;
            throw new \RuntimeException('Area code is already set');
        });

        $command = new SendSummaryCommand($config, $factory, $notifier, $state);
        $this->assertSame('panth:errormonitor:send-summary', $command->getName());
        return new CommandTester($command);
    }

    public function testDisabledEmailIsReported(): void
    {
        $this->enabled = false;
        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Email alerts are disabled in configuration.', $tester->getDisplay());
        $this->assertSame(['global'], $this->areaCodes);
        $this->assertSame([], $this->filters);
    }

    public function testMissingRecipientsFails(): void
    {
        $this->recipients = [];
        $tester = $this->tester();
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('No valid recipient email addresses configured.', $tester->getDisplay());
    }

    public function testNothingToSend(): void
    {
        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('nothing to send', $tester->getDisplay());
        $this->assertSame([], $this->sent);
    }

    public function testSendsQualifyingGroups(): void
    {
        $this->groups = ['a' => new DataObject(['group_id' => 1]), 'b' => new DataObject(['group_id' => 2])];
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Summary email sent covering 2 error group(s).', $tester->getDisplay());
        $this->assertSame([array_values($this->groups)], $this->sent);
        $this->assertSame(ErrorGroup::STATUS_NEW, $this->filters['status']);
        $this->assertSame(['in' => ['critical', 'alert', 'emergency']], $this->filters['severity']);
        $this->assertArrayHasKey('gteq', $this->filters['last_seen_at']);
        $this->assertContains(['setPageSize', [40]], $this->calls);
        $this->assertContains(['setOrder', ['severity', 'DESC']], $this->calls);
    }

    public function testSendFailureReturnsFailure(): void
    {
        $this->groups = [new DataObject(['group_id' => 1])];
        $this->sendResult = false;
        $tester = $this->tester();
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Email send failed', $tester->getDisplay());
    }
}
