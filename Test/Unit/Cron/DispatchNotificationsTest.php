<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Cron;

use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\FlagManager;
use Panth\ErrorMonitor\Cron\DispatchNotifications;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\EmailNotifier;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\Collection;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DispatchNotificationsTest extends TestCase
{
    private bool $emailEnabled = true;

    private array $recipients = ['ops@example.com'];

    private int $sendHour = 0;

    private string $minSeverity = 'warning';

    private array $flags = [];

    private array $groups = [];

    private array $filters = [];

    private array $collectionCalls = [];

    private int $created = 0;

    private ?bool $sendResult = true;

    private array $sent = [];

    private array $updates = [];

    private array $warnings = [];

    private function cron(): DispatchNotifications
    {
        $config = $this->createStub(Config::class);
        $config->method('isEmailEnabled')->willReturnCallback(fn () => $this->emailEnabled);
        $config->method('getEmailRecipients')->willReturnCallback(fn () => $this->recipients);
        $config->method('getEmailSendHour')->willReturnCallback(fn () => $this->sendHour);
        $config->method('getEmailMinSeverity')->willReturnCallback(fn () => $this->minSeverity);
        $config->method('getEmailMaxPerRun')->willReturn(25);

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use ($collection) {
            $this->filters[$field] = $cond;
            return $collection;
        });
        foreach (['setOrder', 'setPageSize', 'setCurPage'] as $method) {
            $collection->method($method)->willReturnCallback(function (...$args) use ($collection, $method) {
                $this->collectionCalls[] = [$method, $args];
                return $collection;
            });
        }
        $collection->method('getItems')->willReturnCallback(fn () => $this->groups);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($collection) {
            $this->created++;
            return $collection;
        });

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('update')->willReturnCallback(function ($table, array $bind, $where): int {
            $this->updates[] = [$table, $bind, $where];
            return 1;
        });
        $resource = $this->createStub(ErrorGroupResource::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getMainTable')->willReturn('panth_error_group');

        $notifier = $this->createStub(EmailNotifier::class);
        $notifier->method('send')->willReturnCallback(function (array $groups): bool {
            if ($this->sendResult === null) {
                throw new \RuntimeException('smtp exploded');
            }
            $this->sent[] = $groups;
            return $this->sendResult;
        });

        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(fn ($code) => $this->flags[$code] ?? null);
        $flagManager->method('saveFlag')->willReturnCallback(function ($code, $value): bool {
            $this->flags[$code] = $value;
            return true;
        });

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = $message;
        });

        return new DispatchNotifications($config, $factory, $resource, $notifier, $flagManager, $logger);
    }

    private function flagCode(): string
    {
        return 'panth_errormonitor_summary_' . gmdate('Y-m-d');
    }

    public function testDisabledEmailDoesNothing(): void
    {
        $this->emailEnabled = false;
        $this->cron()->execute();
        $this->assertSame(0, $this->created);
    }

    public function testNoRecipientsDoesNothing(): void
    {
        $this->recipients = [];
        $this->cron()->execute();
        $this->assertSame(0, $this->created);
    }

    public function testBeforeSendHourDoesNothing(): void
    {
        $this->sendHour = 24;
        $this->cron()->execute();
        $this->assertSame(0, $this->created);
    }

    public function testAlreadySentTodayDoesNothing(): void
    {
        $this->flags[$this->flagCode()] = 1;
        $this->cron()->execute();
        $this->assertSame(0, $this->created);
    }

    public function testNoQualifyingGroupsSendsNothing(): void
    {
        $this->cron()->execute();
        $this->assertSame(1, $this->created);
        $this->assertSame([], $this->sent);
        $this->assertArrayNotHasKey($this->flagCode(), $this->flags);
    }

    public function testSuccessfulSendMarksGroupsAndFlag(): void
    {
        $this->groups = [
            7 => new DataObject(['group_id' => 7]),
            9 => new DataObject(['group_id' => '9']),
            0 => new DataObject(['group_id' => 0]),
        ];
        $this->cron()->execute();

        $this->assertSame(ErrorGroup::STATUS_NEW, $this->filters['status']);
        $this->assertSame(
            ['in' => ['warning', 'error', 'critical', 'alert', 'emergency']],
            $this->filters['severity']
        );
        $since = strtotime($this->filters['last_seen_at']['gteq'] . ' UTC');
        $this->assertEqualsWithDelta(time() - 86400, $since, 5);
        $this->assertContains(['setPageSize', [25]], $this->collectionCalls);
        $this->assertContains(['setCurPage', [1]], $this->collectionCalls);

        $this->assertCount(1, $this->sent);
        $this->assertSame(array_values($this->groups), $this->sent[0]);
        $this->assertSame(1, $this->flags[$this->flagCode()]);

        $this->assertCount(1, $this->updates);
        [$table, $bind, $where] = $this->updates[0];
        $this->assertSame('panth_error_group', $table);
        $this->assertSame(gmdate('Y-m-d'), $bind['last_emailed_date']);
        $this->assertInstanceOf(Expression::class, $bind['emailed_count']);
        $this->assertSame('emailed_count + 1', (string)$bind['emailed_count']);
        $this->assertSame(['group_id IN (?)' => [7, 9]], $where);
    }

    public function testGroupsWithoutIdsStillSetFlagButSkipUpdate(): void
    {
        $this->groups = [new DataObject(['group_id' => 0])];
        $this->cron()->execute();
        $this->assertSame(1, $this->flags[$this->flagCode()]);
        $this->assertSame([], $this->updates);
    }

    public function testFailedSendLeavesStateUntouched(): void
    {
        $this->groups = [new DataObject(['group_id' => 3])];
        $this->sendResult = false;
        $this->cron()->execute();
        $this->assertArrayNotHasKey($this->flagCode(), $this->flags);
        $this->assertSame([], $this->updates);
    }

    public function testUnknownMinimumSeverityIncludesEveryLevel(): void
    {
        $this->minSeverity = 'bogus';
        $this->cron()->execute();
        $this->assertCount(8, $this->filters['severity']['in']);
    }

    public function testExceptionsAreLogged(): void
    {
        $this->groups = [new DataObject(['group_id' => 3])];
        $this->sendResult = null;
        $this->cron()->execute();
        $this->assertSame(['[PanthErrorMonitor] dispatch failed: smtp exploded'], $this->warnings);
    }
}
