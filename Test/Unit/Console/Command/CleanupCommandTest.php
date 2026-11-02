<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Console\Command;

use Panth\ErrorMonitor\Console\Command\CleanupCommand;
use Panth\ErrorMonitor\Cron\Cleanup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CleanupCommandTest extends TestCase
{
    public function testReportsPrunedCounts(): void
    {
        $cleanup = $this->createMock(Cleanup::class);
        $cleanup->expects($this->once())->method('run')
            ->willReturn(['events' => 12, 'groups' => 3, 'unresolved_groups' => 1]);
        $command = new CleanupCommand($cleanup);
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame('panth:errormonitor:cleanup', $command->getName());
        $this->assertStringContainsString(
            'Pruned 12 event row(s), 3 resolved group(s) and 1 unresolved group(s).',
            $tester->getDisplay()
        );
    }
}
