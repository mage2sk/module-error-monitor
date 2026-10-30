<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Console\Command;

use Panth\ErrorMonitor\Service\Refingerprinter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RegroupCommand extends Command
{
    public const NAME = 'panth:errormonitor:regroup';

    private const OPT_DRY_RUN = 'dry-run';

    public function __construct(
        private readonly Refingerprinter $refingerprinter,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription(
                'Deprecated: runs ' . RefingerprintCommand::NAME . ' (re-fingerprint existing groups and merge duplicates).'
            )
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Plan only - do not modify the database.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(
            '<comment>' . self::NAME . ' is deprecated; use ' . RefingerprintCommand::NAME . ' instead.</comment>'
        );
        $dryRun = (bool)$input->getOption(self::OPT_DRY_RUN);
        try {
            $stats = $this->refingerprinter->run($dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>Regroup failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if ($dryRun) {
            $output->writeln(sprintf(
                '<info>[dry-run] scanned=%d  would_update=%d  would_merge=%d  would_delete=%d</info>',
                $stats['scanned'],
                $stats['updated_groups'],
                $stats['merged_groups'],
                $stats['deleted_groups']
            ));
            return Command::SUCCESS;
        }
        $output->writeln(sprintf(
            '<info>Regrouped: scanned=%d  updated=%d  merged=%d  deleted_groups=%d  events_moved=%d</info>',
            $stats['scanned'],
            $stats['updated_groups'],
            $stats['merged_groups'],
            $stats['deleted_groups'],
            $stats['events_moved']
        ));
        return Command::SUCCESS;
    }
}
