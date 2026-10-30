<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Panth\ErrorMonitor\Console\Command\SendSummaryCommand;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\EmailNotifier;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SendSummaryCommandTest extends TestCase
{
    private bool $enabled = true;

    private array $recipients = ['ops@example.com'];

    private array $rows = [];

    private int $built = 0;

    private bool $sendResult = true;

    private array $sent = [];

    private array $areaCodes = [];

    private function tester(): CommandTester
    {
        $config = $this->createStub(Config::class);
        $config->method('isEmailEnabled')->willReturnCallback(fn () => $this->enabled);
        $config->method('getEmailRecipients')->willReturnCallback(fn () => $this->recipients);

        $notifier = $this->createStub(EmailNotifier::class);
        $notifier->method('sendDigest')->willReturnCallback(function (array $digest): bool {
            $this->sent[] = $digest;
            return $this->sendResult;
        });

        $state = $this->createStub(State::class);
        $state->method('setAreaCode')->willReturnCallback(function ($code): void {
            $this->areaCodes[] = $code;
            throw new \RuntimeException('Area code is already set');
        });

        $builder = $this->createStub(DigestBuilder::class);
        $builder->method('build')->willReturnCallback(function (): array {
            $this->built++;
            return [
                'rows' => $this->rows,
                'group_count' => count($this->rows),
                'since' => '2026-10-28 05:00:00 PDT',
                'timezone' => 'America/Los_Angeles',
                'hidden_ignored' => 2,
                'hidden_bot' => 1,
                'hidden_graphql' => 0,
                'not_shown' => 0,
            ];
        });

        $command = new SendSummaryCommand(
            $config,
            $this->createStub(CollectionFactory::class),
            $notifier,
            $state,
            null,
            $builder
        );
        $this->assertSame('panth:errormonitor:send-summary', $command->getName());
        return new CommandTester($command);
    }

    private function row(int $id): array
    {
        return [
            'group_id' => $id,
            'severity' => 'error',
            'source' => 'js',
            'count_label' => '6',
            'occurrence_count' => 1702,
            'bot_pct' => 50,
            'first_seen' => '2026-07-01 10:00:00 PDT',
            'last_seen' => '2026-10-28 13:34:40 PDT',
            'message' => 'Boom ' . $id,
        ];
    }

    public function testDisabledEmailIsReported(): void
    {
        $this->enabled = false;
        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Email alerts are disabled in configuration.', $tester->getDisplay());
        $this->assertSame(['global'], $this->areaCodes);
        $this->assertSame(0, $this->built);
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

    public function testSendsDigest(): void
    {
        $this->rows = [$this->row(1), $this->row(2)];
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Summary email sent covering 2 error group(s).', $tester->getDisplay());
        $this->assertCount(1, $this->sent);
        $this->assertSame($this->rows, $this->sent[0]['rows']);
    }

    public function testDryRunPrintsWithoutSending(): void
    {
        $this->enabled = false;
        $this->recipients = [];
        $this->rows = [$this->row(4)];
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('hidden: 2 ignore rules, 1 bot filter', $display);
        $this->assertStringContainsString('#4 [error/js] 6 in last 24 h (1702 total), bots 50%', $display);
        $this->assertSame([], $this->sent);
    }

    public function testSendFailureReturnsFailure(): void
    {
        $this->rows = [$this->row(1)];
        $this->sendResult = false;
        $tester = $this->tester();
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Email send failed', $tester->getDisplay());
    }
}
