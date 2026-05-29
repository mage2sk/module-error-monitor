<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Re-fingerprints every existing error group using the current normaliser and
 * type extractor, then merges any rows that now share a fingerprint. Auto-runs
 * once on setup:upgrade via the data patch; this command lets an admin run it
 * on demand (e.g. after manual data edits, or with --dry-run to preview).
 *
 *   bin/magento panth:errormonitor:regroup [--dry-run]
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Console\Command;

use Panth\ErrorMonitor\Service\Regrouper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RegroupCommand extends Command
{
    private const OPT_DRY_RUN = 'dry-run';

    public function __construct(
        private readonly Regrouper $regrouper,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('panth:errormonitor:regroup')
            ->setDescription('Re-fingerprint existing error groups and merge duplicates.')
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Plan only — do not modify the database.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption(self::OPT_DRY_RUN)) {
            $plan = $this->regrouper->plan();
            $output->writeln(sprintf(
                '<info>[dry-run] scanned=%d  would_update=%d  would_merge=%d  would_delete=%d</info>',
                $plan['scanned'],
                $plan['would_update'],
                $plan['would_merge'],
                $plan['would_delete']
            ));
            return Command::SUCCESS;
        }

        try {
            $stats = $this->regrouper->regroupAll();
            $output->writeln(sprintf(
                '<info>Regrouped: scanned=%d  updated=%d  merged=%d  deleted_groups=%d  events_moved=%d</info>',
                $stats['scanned'],
                $stats['updated'],
                $stats['merged'],
                $stats['deleted_groups'],
                $stats['events_moved']
            ));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Regroup failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
