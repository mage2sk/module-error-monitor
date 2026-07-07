<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Console\Command;

use Panth\ErrorMonitor\Service\DeploymentGuard;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResumeCaptureCommand extends Command
{
    public function __construct(
        private readonly DeploymentGuard $deploymentGuard,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('panth:errormonitor:resume')
            ->setDescription('Resume Error Monitor capture after an explicit pause (does not affect maintenance mode).');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->deploymentGuard->resume();
        $status = $this->deploymentGuard->status();
        if ($status['maintenance']) {
            $output->writeln('<comment>Pause flag cleared, but capture is still suspended because MaintenanceMode is ON.</comment>');
        } else {
            $output->writeln('<info>Error Monitor capture resumed.</info>');
        }
        return Command::SUCCESS;
    }
}
