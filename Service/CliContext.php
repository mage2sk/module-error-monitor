<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

class CliContext
{
    public const MASK = '***';

    private const MAX_ARGV = 1000;

    private const MAX_FRAMES = 30;

    private const SENSITIVE_NAME = '/pass|pwd|secret|token|key|auth|credential|cookie|session|salt|private|signature/i';

    private const PII_OPTION = '/^--?(?:admin-)?(?:email|firstname|lastname|first-name|last-name|telephone|phone)$/i';

    private const SENSITIVE_CONFIG_PATH = '/key|secret|password|passwd|token|private|credential|signature/i';

    private const CONFIG_COMMANDS = ['config:set', 'config:sensitive:set'];

    private const CONFIG_VALUE_OPTIONS = ['--scope', '--scope-code'];

    private const SAFE_OPTIONS = ['--bootstrap'];

    private const SKIP_FRAME = '~(?:/vendor/monolog/|/Magento/Framework/Logger/|/framework/Logger/|'
        . '/Panth/ErrorMonitor/|/module-error-monitor/|Psr\\\\Log\\\\| Monolog\\\\| Magento\\\\Framework\\\\Logger\\\\|'
        . ' Panth\\\\ErrorMonitor\\\\(?!Test\\\\))~';

    private static ?string $cronJob = null;

    public static function setCronJob(?string $jobCode): void
    {
        $jobCode = $jobCode !== null ? trim($jobCode) : null;
        self::$cronJob = $jobCode !== '' ? $jobCode : null;
    }

    public static function getCronJob(): ?string
    {
        return self::$cronJob;
    }

    public function sapi(): string
    {
        return PHP_SAPI;
    }

    public function collect(string $message = '', ?array $argv = null): array
    {
        $argv ??= $this->serverArgv();
        $context = ['sapi' => $this->sapi()];
        if ($argv !== []) {
            $context['argv'] = $this->sanitizeArgv($argv);
        }
        $job = self::$cronJob ?? $this->cronJobFromMessage($message);
        if ($job !== null) {
            $context['cron_job'] = $job;
        }
        return $context;
    }

    public function cronJobFromMessage(string $message): ?string
    {
        if (preg_match('/\bCron Job ([A-Za-z0-9_.:\-]{1,191}) (?:has an error|is run|is failed|failed)/', $message, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    public function sanitizeArgv(array $argv): string
    {
        $out = [];
        $maskNext = false;
        $optionValueNext = false;
        $configCommand = null;
        $positional = 0;
        $maskConfigValue = false;
        foreach (array_values($argv) as $arg) {
            if (!is_scalar($arg)) {
                continue;
            }
            $arg = (string)$arg;
            if ($maskNext) {
                $out[] = self::MASK;
                $maskNext = false;
                continue;
            }
            if ($optionValueNext) {
                $out[] = $this->looksSecret($arg) ? self::MASK : $this->maskInline($arg);
                $optionValueNext = false;
                continue;
            }
            if ($configCommand === null && preg_match('/^-p(.+)$/s', $arg) === 1) {
                $out[] = '-p' . self::MASK;
                continue;
            }
            if (preg_match('/^(--?[A-Za-z0-9][\w.\-]*)=(.*)$/s', $arg, $m) === 1) {
                $out[] = $m[1] . '=' . $this->optionValue($m[1], $m[2]);
                continue;
            }
            if (preg_match('/^--?[A-Za-z0-9][\w.\-]*$/', $arg) === 1) {
                $out[] = $arg;
                if ($configCommand !== null && in_array(strtolower($arg), self::CONFIG_VALUE_OPTIONS, true)) {
                    $optionValueNext = true;
                    continue;
                }
                $maskNext = ($configCommand === null && $arg === '-p') || $this->isSensitiveOption($arg);
                continue;
            }
            if ($configCommand !== null) {
                $positional++;
                if ($positional === 1) {
                    $out[] = $arg;
                    $maskConfigValue = $configCommand === 'config:sensitive:set'
                        || preg_match(self::SENSITIVE_CONFIG_PATH, $arg) === 1;
                    continue;
                }
                if ($positional === 2 && $maskConfigValue) {
                    $out[] = self::MASK;
                    continue;
                }
            }
            if (in_array(strtolower($arg), self::CONFIG_COMMANDS, true)) {
                $configCommand = strtolower($arg);
                $positional = 0;
                $out[] = $arg;
                continue;
            }
            $out[] = $this->looksSecret($arg) ? self::MASK : $this->maskInline($arg);
        }
        $line = implode(' ', $out);
        return mb_strlen($line, 'UTF-8') > self::MAX_ARGV ? mb_substr($line, 0, self::MAX_ARGV, 'UTF-8') : $line;
    }

    private function optionValue(string $name, string $value): string
    {
        if ($this->isSensitiveOption($name)) {
            return self::MASK;
        }
        if (in_array(strtolower($name), self::SAFE_OPTIONS, true)) {
            return $this->maskInline($value);
        }
        if (preg_match('/^([A-Za-z_][\w.\-]*)=([^=]*)$/', $value, $m) === 1
            && preg_match(self::SENSITIVE_NAME, $m[1]) !== 1) {
            return $this->maskInline($value);
        }
        return $this->looksSecret($value) ? self::MASK : $this->maskInline($value);
    }

    private function isSensitiveOption(string $name): bool
    {
        return preg_match(self::SENSITIVE_NAME, $name) === 1
            || preg_match(self::PII_OPTION, $name) === 1;
    }

    private function maskInline(string $value): string
    {
        $value = $this->maskUrlPassword($value);
        return (string)preg_replace(
            '/((?:^|[;&?,\s:])(?:password|passwd|pwd|pass|secret|token|api_?key))=([^;&,\s]*)/i',
            '$1=' . self::MASK,
            $value
        );
    }

    public function backtrace(): ?string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80);
        $lines = [];
        foreach ($frames as $frame) {
            $file = (string)($frame['file'] ?? '');
            $call = (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '')
                . ($frame['function'] ?? '');
            if (preg_match(self::SKIP_FRAME, $file . ' ' . $call) === 1) {
                continue;
            }
            $lines[] = '#' . count($lines) . ' '
                . ($file !== '' ? $file . '(' . (int)($frame['line'] ?? 0) . ')' : '[internal function]')
                . ': ' . $call . '()';
            if (count($lines) >= self::MAX_FRAMES) {
                break;
            }
        }
        return $lines !== [] ? implode("\n", $lines) : null;
    }

    private function serverArgv(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        return is_array($argv) ? $argv : [];
    }

    private function looksSecret(string $value): bool
    {
        if (strlen($value) < 24 || (str_contains($value, '/') && !str_ends_with($value, '='))) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9+\/=_\-.]+$/', $value) === 1
            && preg_match('/\d/', $value) === 1
            && preg_match('/[A-Za-z]/', $value) === 1;
    }

    private function maskUrlPassword(string $value): string
    {
        return (string)preg_replace('~(://[^:/@\s]+):[^@/\s]+@~', '$1:' . self::MASK . '@', $value);
    }
}
