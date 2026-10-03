<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Console\Command;

use Panth\ErrorMonitor\Console\Command\ResumeCaptureCommand;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ResumeCaptureCommandTest extends TestCase
{
    private function tester(bool $maintenance): CommandTester
    {
        $guard = $this->createMock(DeploymentGuard::class);
        $guard->expects($this->once())->method('resume');
        $guard->expects($this->once())->method('status')->willReturn(['maintenance' => $maintenance]);
        return new CommandTester(new ResumeCaptureCommand($guard));
    }

    public function testResumes(): void
    {
        $tester = $this->tester(false);
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Error Monitor capture resumed.', $tester->getDisplay());
    }

    public function testWarnsWhenMaintenanceModeStillOn(): void
    {
        $tester = $this->tester(true);
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('still suspended because MaintenanceMode is ON', $tester->getDisplay());
        $this->assertStringNotContainsString('capture resumed.', $tester->getDisplay());
    }

    public function testCommandName(): void
    {
        $command = new ResumeCaptureCommand($this->createStub(DeploymentGuard::class));
        $this->assertSame('panth:errormonitor:resume', $command->getName());
    }
}
