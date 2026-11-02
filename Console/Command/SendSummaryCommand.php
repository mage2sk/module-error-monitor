<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\EmailNotifier;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SendSummaryCommand extends Command
{
    private const OPT_DRY_RUN = 'dry-run';

    private ?DigestBuilder $digestBuilder;

    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $collectionFactory,
        private readonly EmailNotifier $notifier,
        private readonly State $appState,
        ?string $name = null,
        ?DigestBuilder $digestBuilder = null
    ) {
        $this->digestBuilder = $digestBuilder;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('panth:errormonitor:send-summary')
            ->setDescription('Send the Error Monitor summary email now (ignores the daily send-hour gate).')
            ->addOption(
                self::OPT_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Print the summary that would be sent without sending an email.'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Throwable $e) {
        }

        $dryRun = (bool)$input->getOption(self::OPT_DRY_RUN);
        if (!$dryRun && !$this->config->isEmailEnabled()) {
            $output->writeln('<comment>Email alerts are disabled in configuration.</comment>');
            return Command::SUCCESS;
        }
        if (!$dryRun && $this->config->getEmailRecipients() === []) {
            $output->writeln('<error>No valid recipient email addresses configured.</error>');
            return Command::FAILURE;
        }

        $digest = $this->builder()->build();
        if ($dryRun) {
            $this->printDigest($digest, $output);
            return Command::SUCCESS;
        }
        if (($digest['rows'] ?? []) === []) {
            $output->writeln('<comment>No qualifying errors in the last 24 hours - nothing to send.</comment>');
            return Command::SUCCESS;
        }

        if ($this->notifier->sendDigest($digest)) {
            $output->writeln(sprintf(
                '<info>Summary email sent covering %d error group(s).</info>',
                count($digest['rows'])
            ));
            return Command::SUCCESS;
        }

        $output->writeln('<error>Email send failed - check var/log for details and your mail transport.</error>');
        return Command::FAILURE;
    }

    private function printDigest(array $digest, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '<info>[dry-run] %d group(s) with events since %s (%s); hidden: %d ignore rules, %d bot filter, %d GraphQL'
            . ' client; %d not shown (limit).</info>',
            (int)($digest['group_count'] ?? 0),
            (string)($digest['since'] ?? ''),
            (string)($digest['timezone'] ?? 'UTC'),
            (int)($digest['hidden_ignored'] ?? 0),
            (int)($digest['hidden_bot'] ?? 0),
            (int)($digest['hidden_graphql'] ?? 0),
            (int)($digest['not_shown'] ?? 0)
        ));
        foreach ($digest['rows'] ?? [] as $row) {
            $output->writeln(sprintf(
                '#%d [%s/%s] %s in last 24 h (%d total), bots %d%%, first %s, last %s :: %s',
                (int)$row['group_id'],
                (string)$row['severity'],
                (string)$row['source'],
                (string)$row['count_label'],
                (int)$row['occurrence_count'],
                (int)$row['bot_pct'],
                (string)$row['first_seen'],
                (string)$row['last_seen'],
                mb_substr((string)$row['message'], 0, 100)
            ));
        }
    }

    private function builder(): DigestBuilder
    {
        if ($this->digestBuilder === null) {
            $this->digestBuilder = ObjectManager::getInstance()->get(DigestBuilder::class);
        }
        return $this->digestBuilder;
    }
}
