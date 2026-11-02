<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Console\Command;

use Panth\ErrorMonitor\Console\Command\PauseCaptureCommand;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PauseCaptureCommandTest extends TestCase
{
    private array $pauses = [];

    private function tester(): CommandTester
    {
        $guard = $this->createStub(DeploymentGuard::class);
        $guard->method('pause')->willReturnCallback(function (int $minutes): int {
            $this->pauses[] = $minutes;
            return 1767225600 + $minutes * 60;
        });
        return new CommandTester(new PauseCaptureCommand($guard));
    }

    public function testDefaultsToSixtyMinutes(): void
    {
        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([60], $this->pauses);
        $this->assertStringContainsString(
            'paused for 60 minute(s). Auto-resumes at 2026-01-01 01:00:00 UTC.',
            $tester->getDisplay()
        );
    }

    public function testCustomDuration(): void
    {
        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute(['--minutes' => '15']));
        $this->assertSame([15], $this->pauses);
        $this->assertStringContainsString('2026-01-01 00:15:00 UTC', $tester->getDisplay());
    }

    public function testNonPositiveDurationIsRejected(): void
    {
        $tester = $this->tester();
        $this->assertSame(Command::INVALID, $tester->execute(['-m' => '0']));
        $this->assertSame(Command::INVALID, $tester->execute(['-m' => 'abc']));
        $this->assertSame([], $this->pauses);
        $this->assertStringContainsString('--minutes must be a positive integer.', $tester->getDisplay());
    }

    public function testCommandName(): void
    {
        $command = new PauseCaptureCommand($this->createStub(DeploymentGuard::class));
        $this->assertSame('panth:errormonitor:pause', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('minutes'));
    }
}
