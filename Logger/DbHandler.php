<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\IpAnonymizer;

class DbHandler extends AbstractProcessingHandler
{
    private const GENERIC_CHANNELS = ['main', 'report', '', 'error', 'exception'];

    public function __construct(
        private readonly ErrorRecorder $recorder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly DeploymentGuard $deploymentGuard,
        private readonly Fingerprinter $fingerprinter,
        $level = Logger::NOTICE,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write($record): void
    {
        try {
            if (!$this->scopeConfig->isSetFlag('panth_errormonitor/general/enabled')
                || !$this->scopeConfig->isSetFlag('panth_errormonitor/php_capture/enabled')) {
                return;
            }

            if ($this->deploymentGuard->isCaptureSuspended()) {
                return;
            }

            [$severity, $message, $context, $channel] = $this->normalize($record);
            if ($message === '') {
                return;
            }

            $minSeverity = (string)($this->scopeConfig->getValue('panth_errormonitor/php_capture/min_severity') ?: 'error');
            if (Severity::rank($severity) < Severity::rank($minSeverity)) {
                return;
            }

            if (str_contains($message, ErrorRecorder::INTERNAL_MARKER)) {
                return;
            }

            $this->recorder->record($this->buildPayload($severity, $message, $context, $channel));
        } catch (\Throwable $e) {
            return;
        }
    }

    private function normalize($record): array
    {
        if (is_array($record)) {
            $severity = strtolower((string)($record['level_name'] ?? 'error'));
            $message  = (string)($record['message'] ?? '');
            $context  = isset($record['context']) && is_array($record['context']) ? $record['context'] : [];
            $channel  = (string)($record['channel'] ?? '');
            return [$severity, $message, $context, $channel];
        }

        $level = $record->level ?? null;
        $severity = (is_object($level) && method_exists($level, 'getName'))
            ? strtolower($level->getName())
            : 'error';
        $message = (string)($record->message ?? '');
        $context = (isset($record->context) && is_array($record->context)) ? $record->context : [];
        $channel = (string)($record->channel ?? '');
        return [$severity, $message, $context, $channel];
    }

    private function buildPayload(
        string $severity,
        string $message,
        array $context,
        string $channel
    ): ErrorPayload {
        $type = '';
        $file = null;
        $line = null;
        $stack = null;

        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $type = get_class($exception);
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : $message;
            $file = $exception->getFile();
            $line = $exception->getLine() ?: null;
            $stack = $exception->getTraceAsString();
        } else {
            $pos = stripos($message, 'Stack trace:');
            if ($pos !== false) {
                $stack = trim(substr($message, $pos + strlen('Stack trace:')));
                $message = trim(substr($message, 0, $pos));
            }

            if ($stack === null && preg_match('/^#0\s+\S/', ltrim($message))) {
                $stack = trim($message);
                $message = $this->synthesizeMessageFromTrace($stack);
                [$frameFile, $frameLine] = $this->topFrameLocation($stack);
                if ($frameFile !== null) {
                    $file = $frameFile;
                    $line = $frameLine;
                }
            }

            $extracted = $this->fingerprinter->extractType($message);
            if ($extracted !== null) {
                $type = $extracted;
            } elseif (!in_array(strtolower($channel), self::GENERIC_CHANNELS, true)) {
                $type = $channel;
            } else {
                $type = 'error';
            }
        }

        unset($context['exception']);
        $ctx = ['channel' => $channel];
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $ctx[(string)$key] = $value;
            }
        }

        return new ErrorPayload(
            source: ErrorGroup::SOURCE_PHP,
            severity: $severity,
            type: $type,
            message: $message,
            file: $file,
            line: $line !== null ? (int)$line : null,
            stackTrace: $stack,
            url: $this->serverValue('REQUEST_URI'),
            referer: $this->serverValue('HTTP_REFERER'),
            userAgent: $this->serverValue('HTTP_USER_AGENT'),
            ip: $this->ipAnonymizer->process($this->serverValue('REMOTE_ADDR')),
            httpMethod: $this->serverValue('REQUEST_METHOD'),
            context: $ctx,
            storeId: 0
        );
    }

    private function serverValue(string $key): ?string
    {
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        return null;
    }

    private function synthesizeMessageFromTrace(string $stack): string
    {
        if (preg_match('/^#0\s+(\S+?)(?:\((\d+)\))?:\s+(\S+?(?:->|::)\S+?)\s*\(/m', $stack, $m)) {
            $file = (string)$m[1];
            $line = (string)($m[2] ?? '');
            $call = (string)$m[3];
            $base = $this->shortFilePath($file);
            return $call . '() at ' . $base . ($line !== '' ? ':' . $line : '');
        }

        $first = strtok($stack, "\n");
        if (is_string($first) && $first !== '') {
            return mb_substr(trim($first), 0, 191);
        }
        return 'Cron exception (see stack trace)';
    }

    private function topFrameLocation(string $stack): array
    {
        if (preg_match('/^#0\s+(\S+?)(?:\((\d+)\))?:/m', $stack, $m)) {
            return [(string)$m[1], isset($m[2]) ? (int)$m[2] : null];
        }
        return [null, null];
    }

    private function shortFilePath(string $path): string
    {
        if (preg_match('~/vendor/(.+)$~', $path, $m)) {
            return $m[1];
        }
        if (preg_match('~/(?:app/code|generated|var)/(.+)$~', $path, $m)) {
            return $m[1];
        }
        return basename($path);
    }
}
