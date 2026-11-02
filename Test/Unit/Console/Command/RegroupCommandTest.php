<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Console\Command;

use Panth\ErrorMonitor\Console\Command\RegroupCommand;
use Panth\ErrorMonitor\Service\Refingerprinter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RegroupCommandTest extends TestCase
{
    private function stats(bool $dryRun): array
    {
        return [
            'dry_run' => $dryRun, 'scanned' => 10, 'merged_groups' => 2, 'deleted_groups' => 3,
            'updated_groups' => 4, 'reclassified_groups' => 0, 'events_moved' => $dryRun ? 9 : 40,
            'events_trimmed' => 0, 'merges' => [],
        ];
    }

    public function testDryRunDelegatesToRefingerprinter(): void
    {
        $service = $this->createMock(Refingerprinter::class);
        $service->expects($this->once())->method('run')->with(true)->willReturn($this->stats(true));
        $tester = new CommandTester(new RegroupCommand($service));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString(
            '[dry-run] scanned=10  would_update=4  would_merge=2  would_delete=3',
            $tester->getDisplay()
        );
        $this->assertStringContainsString('deprecated', $tester->getDisplay());
    }

    public function testRegroupReportsStats(): void
    {
        $service = $this->createMock(Refingerprinter::class);
        $service->expects($this->once())->method('run')->with(false)->willReturn($this->stats(false));
        $command = new RegroupCommand($service);
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame('panth:errormonitor:regroup', $command->getName());
        $this->assertStringContainsString(
            'Regrouped: scanned=10  updated=4  merged=2  deleted_groups=3  events_moved=40',
            $tester->getDisplay()
        );
    }

    public function testFailureReturnsFailureCode(): void
    {
        $service = $this->createStub(Refingerprinter::class);
        $service->method('run')->willThrowException(new \RuntimeException('deadlock'));
        $tester = new CommandTester(new RegroupCommand($service));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Regroup failed: deadlock', $tester->getDisplay());
    }
}
