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
            ->setDescription('Prune old Error Monitor events, resolved groups and stale new groups per retention settings.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->cleanup->run();
        $output->writeln(sprintf(
            '<info>Pruned %d event row(s), %d resolved group(s) and %d unresolved group(s).</info>',
            $result['events'],
            $result['groups'],
            $result['unresolved_groups']
        ));
        $counters = $this->cleanup->pruneCounters();
        if (is_array($counters) && ($counters['hourly_counters'] ?? 0) + ($counters['rate_limit_rows'] ?? 0) > 0) {
            $output->writeln(sprintf(
                '<info>Pruned %d hourly counter row(s) and %d rate limit row(s).</info>',
                (int)$counters['hourly_counters'],
                (int)$counters['rate_limit_rows']
            ));
        }
        return Command::SUCCESS;
    }
}
