<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Monolog handler attached to Magento's system logger (see etc/di.xml). It
 * receives every log record at NOTICE level and above — which includes every
 * uncaught exception Magento routes through the logger — and forwards the
 * ones that meet the configured minimum severity to the ErrorRecorder.
 *
 * Design notes:
 *   - bubble = true: we never stop the record from also reaching the normal
 *     file handlers, so var/log/*.log keeps working exactly as before.
 *   - Heavy collaborators (recorder, IP anonymiser) are injected as Proxies
 *     because the system logger is constructed extremely early in bootstrap,
 *     before the DB is ready. Nothing touches the database until write().
 *   - Everything in write() is wrapped: a logging handler must never throw.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\IpAnonymizer;

class DbHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly ErrorRecorder $recorder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly IpAnonymizer $ipAnonymizer,
        int|string|Level $level = Level::Notice,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            if (!$this->scopeConfig->isSetFlag('panth_errormonitor/general/enabled')
                || !$this->scopeConfig->isSetFlag('panth_errormonitor/php_capture/enabled')) {
                return;
            }

            $severity = strtolower($record->level->getName());
            $minSeverity = (string)($this->scopeConfig->getValue('panth_errormonitor/php_capture/min_severity') ?: 'error');
            if (Severity::rank($severity) < Severity::rank($minSeverity)) {
                return;
            }

            // Never re-capture our own internal warnings.
            if (str_contains($record->message, ErrorRecorder::INTERNAL_MARKER)) {
                return;
            }

            $payload = $this->buildPayload($record, $severity);
            $this->recorder->record($payload);
        } catch (\Throwable $e) {
            // Swallow — capturing an error must never raise one.
            return;
        }
    }

    private function buildPayload(LogRecord $record, string $severity): ErrorPayload
    {
        $type = '';
        $message = $record->message;
        $file = null;
        $line = null;
        $stack = null;

        // LogRecord::$context is readonly in Monolog 3 — work on a by-value copy.
        $contextData = $record->context;
        $exception = $contextData['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $type = get_class($exception);
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : $record->message;
            $file = $exception->getFile();
            $line = $exception->getLine() ?: null;
            $stack = $exception->getTraceAsString();
        } else {
            // Split an inline "...message... Stack trace: ..." log line.
            $pos = stripos($message, 'Stack trace:');
            if ($pos !== false) {
                $stack = trim(substr($message, $pos + strlen('Stack trace:')));
                $message = trim(substr($message, 0, $pos));
            }
            $type = $record->channel !== '' ? $record->channel : 'error';
        }

        unset($contextData['exception']);
        $context = ['channel' => $record->channel];
        foreach ($contextData as $key => $value) {
            if (is_scalar($value)) {
                $context[(string)$key] = $value;
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
            context: $context,
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
}
