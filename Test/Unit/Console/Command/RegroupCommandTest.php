<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Console\Command;

use Panth\ErrorMonitor\Console\Command\RegroupCommand;
use Panth\ErrorMonitor\Service\Regrouper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RegroupCommandTest extends TestCase
{
    public function testDryRunOnlyPlans(): void
    {
        $regrouper = $this->createMock(Regrouper::class);
        $regrouper->expects($this->once())->method('plan')->willReturn([
            'scanned' => 10, 'would_update' => 4, 'would_merge' => 2, 'would_delete' => 3,
        ]);
        $regrouper->expects($this->never())->method('regroupAll');
        $tester = new CommandTester(new RegroupCommand($regrouper));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString(
            '[dry-run] scanned=10  would_update=4  would_merge=2  would_delete=3',
            $tester->getDisplay()
        );
    }

    public function testRegroupReportsStats(): void
    {
        $regrouper = $this->createMock(Regrouper::class);
        $regrouper->expects($this->never())->method('plan');
        $regrouper->expects($this->once())->method('regroupAll')->willReturn([
            'scanned' => 8, 'updated' => 2, 'merged' => 1, 'deleted_groups' => 3, 'events_moved' => 40,
        ]);
        $command = new RegroupCommand($regrouper);
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame('panth:errormonitor:regroup', $command->getName());
        $this->assertStringContainsString(
            'Regrouped: scanned=8  updated=2  merged=1  deleted_groups=3  events_moved=40',
            $tester->getDisplay()
        );
    }

    public function testFailureReturnsFailureCode(): void
    {
        $regrouper = $this->createStub(Regrouper::class);
        $regrouper->method('regroupAll')->willThrowException(new \RuntimeException('deadlock'));
        $tester = new CommandTester(new RegroupCommand($regrouper));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Regroup failed: deadlock', $tester->getDisplay());
    }
}
