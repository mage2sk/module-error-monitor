<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Console\Command;

use Panth\ErrorMonitor\Service\DeploymentGuard;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PauseCaptureCommand extends Command
{
    private const OPT_MINUTES = 'minutes';

    public function __construct(
        private readonly DeploymentGuard $deploymentGuard,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('panth:errormonitor:pause')
            ->setDescription('Suspend Error Monitor capture for the given number of minutes (auto-expires).')
            ->addOption(
                self::OPT_MINUTES,
                'm',
                InputOption::VALUE_REQUIRED,
                'Pause duration in minutes (default 60).',
                '60'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $minutes = (int)$input->getOption(self::OPT_MINUTES);
        if ($minutes < 1) {
            $output->writeln('<error>--minutes must be a positive integer.</error>');
            return Command::INVALID;
        }
        $expiry = $this->deploymentGuard->pause($minutes);
        $output->writeln(sprintf(
            '<info>Error Monitor capture paused for %d minute(s). Auto-resumes at %s UTC.</info>',
            $minutes,
            gmdate('Y-m-d H:i:s', $expiry)
        ));
        return Command::SUCCESS;
    }
}
