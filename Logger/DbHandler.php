<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Monolog handler attached to Magento's system logger (see etc/di.xml). It
 * receives every log record at NOTICE level and above — which includes every
 * uncaught exception Magento routes through the logger — and forwards the
 * ones that meet the configured minimum severity to the ErrorRecorder.
 *
 * CROSS-VERSION NOTE: Magento 2.4.6 ships Monolog 2 (where the abstract
 * AbstractProcessingHandler::write() takes an `array $record`) while 2.4.7+
 * ships Monolog 3 (where it takes a `Monolog\LogRecord $record`). To run on
 * both from a single codebase we:
 *   - declare write() with NO parameter type (a wider/contravariant override
 *     is legal against either parent signature), then normalise the record at
 *     runtime via is_array();
 *   - never reference the Monolog 3-only `Monolog\Level` enum — the level is
 *     defaulted with the integer Logger::NOTICE constant, which exists in both
 *     Monolog 2 and 3.
 *
 * Design notes:
 *   - bubble = true: the record still reaches the normal file handlers, so
 *     var/log/*.log keeps working exactly as before.
 *   - Heavy collaborators are injected as Proxies because the system logger is
 *     built extremely early in bootstrap, before the DB is ready. Nothing
 *     touches the database until write().
 *   - Everything in write() is wrapped: a logging handler must never throw.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\IpAnonymizer;

class DbHandler extends AbstractProcessingHandler
{
    /**
     * @param int|string $level Defaulted with Logger::NOTICE (an int constant
     *                          present in both Monolog 2 and 3) — do NOT type
     *                          as Monolog\Level, which is 3-only.
     */
    public function __construct(
        private readonly ErrorRecorder $recorder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly IpAnonymizer $ipAnonymizer,
        $level = Logger::NOTICE,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * @param \Monolog\LogRecord|array $record Monolog 3 LogRecord or Monolog 2 array.
     */
    protected function write($record): void
    {
        try {
            if (!$this->scopeConfig->isSetFlag('panth_errormonitor/general/enabled')
                || !$this->scopeConfig->isSetFlag('panth_errormonitor/php_capture/enabled')) {
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

            // Never re-capture our own internal warnings.
            if (str_contains($message, ErrorRecorder::INTERNAL_MARKER)) {
                return;
            }

            $this->recorder->record($this->buildPayload($severity, $message, $context, $channel));
        } catch (\Throwable $e) {
            // Swallow — capturing an error must never raise one.
            return;
        }
    }

    /**
     * Normalise a Monolog 2 array record or a Monolog 3 LogRecord object into
     * a flat tuple: [severityName, message, contextArray, channel].
     *
     * @param \Monolog\LogRecord|array $record
     * @return array{0:string,1:string,2:array,3:string}
     */
    private function normalize($record): array
    {
        if (is_array($record)) {
            // Monolog 2: ['level' => int, 'level_name' => 'ERROR', 'message' => ..., 'context' => [], 'channel' => ...]
            $severity = strtolower((string)($record['level_name'] ?? 'error'));
            $message  = (string)($record['message'] ?? '');
            $context  = isset($record['context']) && is_array($record['context']) ? $record['context'] : [];
            $channel  = (string)($record['channel'] ?? '');
            return [$severity, $message, $context, $channel];
        }

        // Monolog 3: LogRecord object with ->level (Level enum), ->message, ->context, ->channel.
        $level = $record->level ?? null;
        $severity = (is_object($level) && method_exists($level, 'getName'))
            ? strtolower($level->getName())
            : 'error';
        $message = (string)($record->message ?? '');
        $context = (isset($record->context) && is_array($record->context)) ? $record->context : [];
        $channel = (string)($record->channel ?? '');
        return [$severity, $message, $context, $channel];
    }

    /**
     * @param array<string, mixed> $context
     */
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
            // Split an inline "...message... Stack trace: ..." log line.
            $pos = stripos($message, 'Stack trace:');
            if ($pos !== false) {
                $stack = trim(substr($message, $pos + strlen('Stack trace:')));
                $message = trim(substr($message, 0, $pos));
            }
            $type = $channel !== '' ? $channel : 'error';
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
}
