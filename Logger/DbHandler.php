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
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\IpAnonymizer;

class DbHandler extends AbstractProcessingHandler
{
    /**
     * Channel names that are too generic to use as the error "type" — when the
     * channel is one of these AND no exception is in context, we try to mine
     * the real class out of the message via Fingerprinter::extractType.
     */
    private const GENERIC_CHANNELS = ['main', 'report', '', 'error', 'exception'];

    /**
     * @param int|string $level Defaulted with Logger::NOTICE (an int constant
     *                          present in both Monolog 2 and 3) — do NOT type
     *                          as Monolog\Level, which is 3-only.
     */
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

            // Skip deployment-noise: visitors / bots hitting the site mid-deploy
            // throw exceptions that get logged here; capture is paused while
            // MaintenanceMode is on or an explicit pause flag is active.
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
            // Cron and other code paths sometimes call
            // `$logger->error($exception->getTraceAsString())` — the message
            // IS the raw stack trace ("#0 /path...\n#1 ..."). Without this
            // fixup the event row stores the trace in the `message` column
            // and leaves `stack_trace` NULL, so the detail page renders
            // empty Recent Occurrences (only the {channel: main} context).
            // Move the trace to `stack_trace` and synthesise a one-line
            // human-readable message from the top frame.
            if ($stack === null && preg_match('/^#0\s+\S/', ltrim($message))) {
                $stack = trim($message);
                $message = $this->synthesizeMessageFromTrace($stack);
                [$frameFile, $frameLine] = $this->topFrameLocation($stack);
                if ($frameFile !== null) {
                    $file = $frameFile;
                    $line = $frameLine;
                }
            }
            // Try to mine the real exception class / error family out of the
            // message. The Monolog channel ("main", "report", ...) is almost
            // never the actual error type and produces useless grouping.
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

    /**
     * Derive a one-line, grid-readable message from the first stack frame.
     * Format: "Class::method() at file.php:line" with vendor/ prefix dropped.
     * Falls back to a generic label when the frame can't be parsed.
     */
    private function synthesizeMessageFromTrace(string $stack): string
    {
        // Match: "#0 [path](line): Foo\Bar->baz(args)" with line optional.
        if (preg_match('/^#0\s+(\S+?)(?:\((\d+)\))?:\s+(\S+?(?:->|::)\S+?)\s*\(/m', $stack, $m)) {
            $file = (string)$m[1];
            $line = (string)($m[2] ?? '');
            $call = (string)$m[3];
            $base = $this->shortFilePath($file);
            return $call . '() at ' . $base . ($line !== '' ? ':' . $line : '');
        }
        // Fallback: just take the first frame line, trimmed and capped.
        $first = strtok($stack, "\n");
        if (is_string($first) && $first !== '') {
            return mb_substr(trim($first), 0, 191);
        }
        return 'Cron exception (see stack trace)';
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    private function topFrameLocation(string $stack): array
    {
        if (preg_match('/^#0\s+(\S+?)(?:\((\d+)\))?:/m', $stack, $m)) {
            return [(string)$m[1], isset($m[2]) ? (int)$m[2] : null];
        }
        return [null, null];
    }

    private function shortFilePath(string $path): string
    {
        // Trim any leading absolute prefix up to and including /vendor/, so
        // "/var/www/.../docroot/vendor/foo/bar/X.php" becomes "foo/bar/X.php".
        if (preg_match('~/vendor/(.+)$~', $path, $m)) {
            return $m[1];
        }
        if (preg_match('~/(?:app/code|generated|var)/(.+)$~', $path, $m)) {
            return $m[1];
        }
        return basename($path);
    }
}
