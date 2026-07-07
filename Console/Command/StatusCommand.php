<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    public function __construct(
        private readonly DeploymentGuard $deploymentGuard,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:errormonitor:status')
            ->setDescription('Show every gate that decides whether Error Monitor is capturing right now.')
            ->addOption(
                'reset-auto-detect',
                null,
                InputOption::VALUE_NONE,
                'Wipe the deploy-mtime baseline + auto-pause-until flags so the next request re-baselines from scratch.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Throwable $e) {
        }

        if ($input->getOption('reset-auto-detect')) {
            $this->deploymentGuard->resetAutoDetect();
            $output->writeln('<info>Auto-detect baseline + pause-until flags cleared.</info>');
            $output->writeln('');
        }

        $masterOn = $this->scopeConfig->isSetFlag('panth_errormonitor/general/enabled');
        $phpOn    = $this->scopeConfig->isSetFlag('panth_errormonitor/php_capture/enabled');
        $jsOn     = $this->scopeConfig->isSetFlag('panth_errormonitor/js_capture/enabled');
        $minSev   = (string)($this->scopeConfig->getValue('panth_errormonitor/php_capture/min_severity') ?: 'error');
        $emailOn  = $this->scopeConfig->isSetFlag('panth_errormonitor/email/enabled');
        $filterEco = $this->scopeConfig->isSetFlag('panth_errormonitor/general/filter_ecosystem_alerts');
        $ignoreRaw = (string)$this->scopeConfig->getValue('panth_errormonitor/general/ignore_patterns');
        $ignoreLines = $ignoreRaw === ''
            ? 0
            : count(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $ignoreRaw) ?: [])));

        $deploy = $this->deploymentGuard->status();

        $output->writeln('<comment>== Capture gates ==</comment>');
        $this->row($output, 'Master switch (general/enabled)', $this->yn($masterOn));
        $this->row($output, 'PHP capture (php_capture/enabled)', $this->yn($phpOn));
        $this->row($output, 'JS capture (js_capture/enabled)', $this->yn($jsOn));
        $this->row($output, 'Min PHP severity to capture', $minSev);
        $this->row($output, 'Email alerts (email/enabled)', $this->yn($emailOn));
        $output->writeln('');

        $output->writeln('<comment>== Suspension state ==</comment>');
        $this->row($output, 'Magento maintenance mode', $this->yn($deploy['maintenance']));
        $this->row(
            $output,
            'Explicit pause flag (CLI)',
            $deploy['paused_until'] === null
                ? 'no'
                : 'YES until ' . gmdate('Y-m-d H:i:s', $deploy['paused_until']) . ' UTC'
        );
        $this->row(
            $output,
            'Auto-pause (deploy detect)',
            $deploy['auto_pause_until'] === null
                ? 'no'
                : 'YES until ' . gmdate('Y-m-d H:i:s', $deploy['auto_pause_until']) . ' UTC'
        );
        $this->row($output, 'Auto-pause window (min)', (string)$deploy['window_minutes']);
        $this->row($output, 'Auto-pause reason', $deploy['auto_pause_reason'] ?: '(n/a)');
        $output->writeln('');
        $output->writeln('<options=bold>>>> CAPTURE IS ' . ($deploy['suspended'] ? '<error>SUSPENDED</error>' : '<info>ACTIVE</info>') . ' <<<</options=bold>');
        $output->writeln('');

        $output->writeln('<comment>== Deploy-marker mtimes ==</comment>');
        $now = time();
        $allMtimes = $deploy['watched_mtimes'] + $deploy['last_seen_mtimes'];
        if ($allMtimes === []) {
            $this->row($output, '(no watched paths exist)', '');
        } else {
            $output->writeln(
                '  ' . str_pad('PATH', 60) . '  ' . str_pad('CURRENT', 22) . '  '
                . str_pad('LAST SEEN', 22) . '  AGE'
            );
            foreach ($allMtimes as $path => $_) {
                $cur = $deploy['watched_mtimes'][$path] ?? 0;
                $seen = $deploy['last_seen_mtimes'][$path] ?? 0;
                $output->writeln(
                    '  ' . str_pad($this->shorten($path, 60), 60) . '  '
                    . str_pad($cur ? gmdate('Y-m-d H:i:s', $cur) : '(missing)', 22) . '  '
                    . str_pad($seen ? gmdate('Y-m-d H:i:s', $seen) : '(none)', 22) . '  '
                    . ($cur ? $this->humanAge($now - $cur) : '-')
                );
            }
        }
        $output->writeln('');

        $output->writeln('<comment>== Filters ==</comment>');
        $this->row($output, 'Ecosystem-alert filter on', $this->yn($filterEco));
        $this->row($output, 'Ignore-patterns lines', (string)$ignoreLines);
        $output->writeln('');

        $output->writeln('<comment>== Recent capture activity ==</comment>');
        try {
            $conn = $this->resource->getConnection();
            $eventsTable = $this->resource->getTableName('panth_error_event');
            $groupsTable = $this->resource->getTableName('panth_error_group');
            $lastHour = $this->countSince($conn, $eventsTable, time() - 3600);
            $last24   = $this->countSince($conn, $eventsTable, time() - 86400);
            $totalGroups = (int)$conn->fetchOne('SELECT COUNT(*) FROM ' . $conn->quoteIdentifier($groupsTable));
            $this->row($output, 'Events recorded in last hour', (string)$lastHour);
            $this->row($output, 'Events recorded in last 24h', (string)$last24);
            $this->row($output, 'Total error groups in table', (string)$totalGroups);
        } catch (\Throwable $e) {
            $this->row($output, 'DB query failed', $e->getMessage());
        }
        $output->writeln('');

        if ($deploy['suspended']) {
            $output->writeln('<info>Tip: --reset-auto-detect clears the baseline + pause-until flags.</info>');
            $output->writeln('<info>     Or: bin/magento config:set panth_errormonitor/general/auto_pause_window_minutes 0</info>');
            $output->writeln('<info>     to disable the auto-pause entirely.</info>');
        }

        return Command::SUCCESS;
    }

    private function row(OutputInterface $output, string $label, string $value): void
    {
        $output->writeln('  ' . str_pad($label, 36) . '  ' . $value);
    }

    private function yn(bool $v): string
    {
        return $v ? 'yes' : 'no';
    }

    private function humanAge(int $sec): string
    {
        if ($sec < 60) {
            return $sec . 's ago';
        }
        if ($sec < 3600) {
            return (int)floor($sec / 60) . 'm ago';
        }
        if ($sec < 86400) {
            return (int)floor($sec / 3600) . 'h ago';
        }
        return (int)floor($sec / 86400) . 'd ago';
    }

    private function shorten(string $path, int $max): string
    {
        if (mb_strlen($path) <= $max) {
            return $path;
        }
        return '…' . mb_substr($path, -($max - 1));
    }

    private function countSince(
        \Magento\Framework\DB\Adapter\AdapterInterface $conn,
        string $table,
        int $sinceEpoch
    ): int {
        return (int)$conn->fetchOne(
            $conn->select()
                ->from($table, [new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
                ->where('created_at >= ?', gmdate('Y-m-d H:i:s', $sinceEpoch))
        );
    }
}
