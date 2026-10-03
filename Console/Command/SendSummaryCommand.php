<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\EmailNotifier;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SendSummaryCommand extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $collectionFactory,
        private readonly EmailNotifier $notifier,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('panth:errormonitor:send-summary')
            ->setDescription('Send the Error Monitor summary email now (ignores the daily send-hour gate).');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Throwable $e) {
        }

        if (!$this->config->isEmailEnabled()) {
            $output->writeln('<comment>Email alerts are disabled in configuration.</comment>');
            return Command::SUCCESS;
        }
        if ($this->config->getEmailRecipients() === []) {
            $output->writeln('<error>No valid recipient email addresses configured.</error>');
            return Command::FAILURE;
        }

        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ErrorGroup::STATUS_NEW)
            ->addFieldToFilter('severity', ['in' => $this->severitiesAtOrAbove($this->config->getEmailMinSeverity())])
            ->addFieldToFilter('last_seen_at', ['gteq' => $since])
            ->setOrder('severity', 'DESC')
            ->setOrder('occurrence_count', 'DESC')
            ->setPageSize($this->config->getEmailMaxPerRun())
            ->setCurPage(1);

        $groups = array_values($collection->getItems());
        if ($groups === []) {
            $output->writeln('<comment>No qualifying errors in the last 24 hours - nothing to send.</comment>');
            return Command::SUCCESS;
        }

        if ($this->notifier->send($groups)) {
            $output->writeln(sprintf('<info>Summary email sent covering %d error group(s).</info>', count($groups)));
            return Command::SUCCESS;
        }

        $output->writeln('<error>Email send failed - check var/log for details and your mail transport.</error>');
        return Command::FAILURE;
    }

    private function severitiesAtOrAbove(string $minSeverity): array
    {
        $min = Severity::rank($minSeverity);
        $out = [];
        foreach (Severity::RANKS as $name => $rank) {
            if ($rank >= $min) {
                $out[] = $name;
            }
        }
        return $out ?: ['error', 'critical', 'alert', 'emergency'];
    }
}
