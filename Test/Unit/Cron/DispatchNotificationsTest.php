<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Cron;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\FlagManager;
use Panth\ErrorMonitor\Cron\DispatchNotifications;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\EmailNotifier;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DispatchNotificationsTest extends TestCase
{
    private bool $emailEnabled = true;

    private array $recipients = ['ops@example.com'];

    private int $sendHour = 0;

    private array $flags = [];

    private array $rows = [];

    private int $built = 0;

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

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('update')->willReturnCallback(function ($table, array $bind, $where): int {
            $this->updates[] = [$table, $bind, $where];
            return 1;
        });
        $resource = $this->createStub(ErrorGroupResource::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getMainTable')->willReturn('panth_error_group');

        $notifier = $this->createStub(EmailNotifier::class);
        $notifier->method('sendDigest')->willReturnCallback(function (array $digest): bool {
            if ($this->sendResult === null) {
                throw new \RuntimeException('smtp exploded');
            }
            $this->sent[] = $digest;
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

        $builder = $this->createStub(DigestBuilder::class);
        $builder->method('build')->willReturnCallback(function (): array {
            $this->built++;
            return ['rows' => $this->rows, 'timezone' => 'UTC'];
        });
        $builder->method('groupIds')->willReturnCallback(
            static fn (array $digest): array => array_map(static fn ($r) => (int)$r['group_id'], $digest['rows'])
        );

        return new DispatchNotifications(
            $config,
            $this->createStub(CollectionFactory::class),
            $resource,
            $notifier,
            $flagManager,
            $logger,
            $builder
        );
    }

    private function flagCode(): string
    {
        return 'panth_errormonitor_summary_' . gmdate('Y-m-d');
    }

    public function testDisabledEmailDoesNothing(): void
    {
        $this->emailEnabled = false;
        $this->cron()->execute();
        $this->assertSame(0, $this->built);
    }

    public function testNoRecipientsDoesNothing(): void
    {
        $this->recipients = [];
        $this->cron()->execute();
        $this->assertSame(0, $this->built);
    }

    public function testBeforeSendHourDoesNothing(): void
    {
        $this->sendHour = 24;
        $this->cron()->execute();
        $this->assertSame(0, $this->built);
    }

    public function testAlreadySentTodayDoesNothing(): void
    {
        $this->flags[$this->flagCode()] = 1;
        $this->cron()->execute();
        $this->assertSame(0, $this->built);
    }

    public function testNoGroupsWithEventsInWindowSendsNothing(): void
    {
        $this->cron()->execute();
        $this->assertSame(1, $this->built);
        $this->assertSame([], $this->sent);
        $this->assertArrayNotHasKey($this->flagCode(), $this->flags);
    }

    public function testSuccessfulSendMarksGroupsAndFlag(): void
    {
        $this->rows = [['group_id' => 7], ['group_id' => 9]];
        $this->cron()->execute();

        $this->assertCount(1, $this->sent);
        $this->assertSame($this->rows, $this->sent[0]['rows']);
        $this->assertSame(1, $this->flags[$this->flagCode()]);

        $this->assertCount(1, $this->updates);
        [$table, $bind, $where] = $this->updates[0];
        $this->assertSame('panth_error_group', $table);
        $this->assertSame(gmdate('Y-m-d'), $bind['last_emailed_date']);
        $this->assertInstanceOf(Expression::class, $bind['emailed_count']);
        $this->assertSame('emailed_count + 1', (string)$bind['emailed_count']);
        $this->assertSame(['group_id IN (?)' => [7, 9]], $where);
    }

    public function testFailedSendLeavesStateUntouched(): void
    {
        $this->rows = [['group_id' => 3]];
        $this->sendResult = false;
        $this->cron()->execute();
        $this->assertArrayNotHasKey($this->flagCode(), $this->flags);
        $this->assertSame([], $this->updates);
    }

    public function testExceptionsAreLogged(): void
    {
        $this->rows = [['group_id' => 3]];
        $this->sendResult = null;
        $this->cron()->execute();
        $this->assertSame(['[PanthErrorMonitor] dispatch failed: smtp exploded'], $this->warnings);
    }
}
