<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Console\Command;

use Magento\Framework\FlagManager;
use Panth\ErrorMonitor\Model\System\Message\RefingerprintPending;
use Panth\ErrorMonitor\Service\Refingerprinter;
use Panth\ErrorMonitor\Service\RefingerprintRolledBackException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RefingerprintCommand extends Command
{
    public const NAME = 'panth:errormonitor:refingerprint';

    public const LEGACY_NAME = 'panth:error-monitor:refingerprint';

    private const OPT_DRY_RUN = 'dry-run';

    private const MAX_LISTED = 50;

    public function __construct(
        private readonly Refingerprinter $refingerprinter,
        ?string $name = null,
        private readonly ?FlagManager $flagManager = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setAliases([self::LEGACY_NAME])
            ->setDescription(
                'Recompute fingerprints of existing error groups with the current normalisation rules, merge'
                . ' groups that now describe the same error and mark stored GraphQL client errors as notice.'
            )
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Show the plan without changing data.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption(self::OPT_DRY_RUN);
        try {
            $stats = $this->refingerprinter->run($dryRun);
        } catch (RefingerprintRolledBackException $e) {
            $output->writeln('<error>Refingerprint failed and was rolled back: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Refingerprint failed before any change was made: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>%sscanned=%d  groups_updated=%d  merge_targets=%d  groups_merged_away=%d  events_moved=%d'
            . '  events_trimmed=%d  graphql_reclassified=%d</info>',
            $dryRun ? '[dry-run] ' : '',
            $stats['scanned'],
            $stats['updated_groups'],
            $stats['merged_groups'],
            $stats['deleted_groups'],
            $stats['events_moved'],
            (int)($stats['events_trimmed'] ?? 0),
            (int)($stats['reclassified_groups'] ?? 0)
        ));
        $listed = 0;
        foreach ($stats['merges'] as $survivorId => $peerIds) {
            if ($listed++ >= self::MAX_LISTED) {
                $output->writeln(sprintf('  ... %d more', count($stats['merges']) - self::MAX_LISTED));
                break;
            }
            $output->writeln(sprintf(
                '  group #%d %s #%s',
                $survivorId,
                $dryRun ? 'would absorb' : 'absorbed',
                implode(', #', $peerIds)
            ));
        }
        if (!$dryRun && $this->flagManager !== null) {
            try {
                $this->flagManager->deleteFlag(RefingerprintPending::FLAG_CODE);
            } catch (\Throwable $e) {
                $output->writeln('<comment>Could not clear the upgrade notice flag: ' . $e->getMessage() . '</comment>');
            }
        }
        return Command::SUCCESS;
    }
}
