<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Console\Command;

use Panth\ErrorMonitor\Cron\Cleanup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupCommand extends Command
{
    public function __construct(
        private readonly Cleanup $cleanup,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('panth:errormonitor:cleanup')
            ->setDescription('Prune old Error Monitor events and resolved groups per retention settings.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->cleanup->run();
        $output->writeln(sprintf(
            '<info>Pruned %d event row(s) and %d resolved group(s).</info>',
            $result['events'],
            $result['groups']
        ));
        return Command::SUCCESS;
    }
}
