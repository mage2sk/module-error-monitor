<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Panth\ErrorMonitor\Model\Config\Source\Severity;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Service\BotDetector;
use Panth\ErrorMonitor\Service\CliContext;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\GraphQlClientErrorFilter;
use Panth\ErrorMonitor\Service\IpAnonymizer;

class DbHandler extends AbstractProcessingHandler
{
    private const GENERIC_CHANNELS = ['main', 'report', '', 'error', 'exception'];

    private const MAX_CONTEXT_VALUE = 2000;

    private const MAX_PREVIOUS = 5;

    private const CONSOLE_USAGE_CLASSES = [
        'Symfony\Component\Console\Exception\CommandNotFoundException',
        'Symfony\Component\Console\Exception\InvalidOptionException',
    ];

    private const CONSOLE_MESSAGE_CLASSES = [
        'Symfony\Component\Console\Exception\InvalidArgumentException',
        'Symfony\Component\Console\Exception\RuntimeException',
    ];

    private const CONSOLE_USAGE_MESSAGE = '/^(?:Command "[^"\n]+" is not defined|There are no commands defined in the "[^"\n]+"'
        . ' namespace|Command "[^"\n]+" is ambiguous|The namespace "[^"\n]+" is ambiguous|Not enough arguments'
        . '|Too many arguments|No arguments expected|The "-{1,2}[^"\n]+" option does not exist'
        . '|The "-{1,2}[^"\n]+" option requires a value|The "-{1,2}[^"\n]+" option does not accept a value)/';

    private const CONSOLE_USAGE_FRAME = '~(?:^|\n)#0 [^\n]*?: Symfony\\\\Component\\\\Console\\\\~';

    private readonly BotDetector $botDetector;

    private readonly GraphQlClientErrorFilter $graphQlFilter;

    private readonly CliContext $cliContext;

    public function __construct(
        private readonly ErrorRecorder $recorder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly DeploymentGuard $deploymentGuard,
        private readonly Fingerprinter $fingerprinter,
        $level = Logger::NOTICE,
        bool $bubble = true,
        ?BotDetector $botDetector = null,
        ?GraphQlClientErrorFilter $graphQlFilter = null,
        ?CliContext $cliContext = null
    ) {
        parent::__construct($level, $bubble);
        $this->botDetector = $botDetector ?? new BotDetector($scopeConfig);
        $this->graphQlFilter = $graphQlFilter ?? new GraphQlClientErrorFilter($scopeConfig);
        $this->cliContext = $cliContext ?? new CliContext();
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

            [$severity, $message, $context, $channel, $extra] = $this->normalize($record);
            if ($message === '') {
                return;
            }

            if (str_contains($message, ErrorRecorder::INTERNAL_MARKER)) {
                return;
            }

            if ($this->isConsoleUsageError($message, $context)) {
                return;
            }

            $userAgent = $this->serverValue('HTTP_USER_AGENT');
            $isBot = $userAgent !== null && $this->botDetector->isBot($userAgent);
            if ($isBot && $this->botDetector->shouldIgnore()) {
                return;
            }

            $graphQlClient = $this->graphQlFilter->isClientSafe($message, $context);
            if ($graphQlClient) {
                $mode = $this->graphQlFilter->mode();
                if ($mode === GraphQlClientErrorFilter::MODE_SKIP) {
                    return;
                }
                if ($mode === GraphQlClientErrorFilter::MODE_NOTICE) {
                    $severity = 'notice';
                } else {
                    $graphQlClient = false;
                }
            }

            if (!$graphQlClient) {
                $minSeverity = (string)($this->scopeConfig->getValue('panth_errormonitor/php_capture/min_severity')
                    ?: 'error');
                if (Severity::rank($severity) < Severity::rank($minSeverity)) {
                    return;
                }
            }

            $payload = $this->buildPayload($severity, $message, $context, $channel, $extra, [
                'is_bot' => $isBot,
                GraphQlClientErrorFilter::CONTEXT_FLAG => $graphQlClient,
            ]);
            $this->recorder->record($payload);
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
            $extra    = isset($record['extra']) && is_array($record['extra']) ? $record['extra'] : [];
            return [$severity, $message, $context, $channel, $extra];
        }

        $level = $record->level ?? null;
        $severity = (is_object($level) && method_exists($level, 'getName'))
            ? strtolower($level->getName())
            : 'error';
        $message = (string)($record->message ?? '');
        $context = (isset($record->context) && is_array($record->context)) ? $record->context : [];
        $channel = (string)($record->channel ?? '');
        $extra = (isset($record->extra) && is_array($record->extra)) ? $record->extra : [];
        return [$severity, $message, $context, $channel, $extra];
    }

    private function buildPayload(
        string $severity,
        string $message,
        array $context,
        string $channel,
        array $extra = [],
        array $flags = []
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
            $stack = $this->stripTraceArguments($exception->getTraceAsString());
        } else {
            $pos = stripos($message, 'Stack trace:');
            if ($pos !== false) {
                $stack = $this->stripTraceArguments(trim(substr($message, $pos + strlen('Stack trace:'))));
                $message = trim(substr($message, 0, $pos));
            }

            if ($stack === null && preg_match('/^#0\s+\S/', ltrim($message))) {
                $stack = $this->stripTraceArguments(trim($message));
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
            $value = $this->contextValue($value);
            if ($value !== null) {
                $ctx[(string)$key] = $value;
            }
        }
        foreach ($extra as $key => $value) {
            $value = $this->contextValue($value);
            if ($value !== null) {
                $ctx['extra_' . $key] = $value;
            }
        }

        if ($exception instanceof \Throwable) {
            $ctx['exception_class'] = get_class($exception);
            $ctx['exception_file'] = $exception->getFile();
            $ctx['exception_line'] = $exception->getLine();
            $previous = $this->previousChain($exception);
            if ($previous !== []) {
                $ctx['previous'] = $previous;
            }
        }

        if ($this->serverValue('REQUEST_METHOD') === null) {
            $ctx += $this->cliContext->collect($message);
            if ($stack === null) {
                $stack = $this->cliContext->backtrace();
            }
        }

        foreach ($flags as $flag => $enabled) {
            if ($enabled) {
                $ctx[$flag] = true;
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

    private function contextValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value, 'UTF-8') > self::MAX_CONTEXT_VALUE
                ? mb_substr($value, 0, self::MAX_CONTEXT_VALUE, 'UTF-8') . '...'
                : $value;
        }
        if (is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \Throwable) {
            return get_class($value) . ': ' . mb_substr($value->getMessage(), 0, 500);
        }
        if (!is_array($value)) {
            return null;
        }
        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
            8
        );
        if (!is_string($json) || $json === '') {
            return null;
        }
        return strlen($json) > self::MAX_CONTEXT_VALUE
            ? '[array with ' . count($value) . ' item(s), too large to store]'
            : $value;
    }

    private function isConsoleUsageError(string $message, array $context): bool
    {
        if ($this->serverValue('REQUEST_METHOD') !== null) {
            return false;
        }
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            foreach (self::CONSOLE_USAGE_CLASSES as $class) {
                if (is_a($exception, $class)) {
                    return true;
                }
            }
            foreach (self::CONSOLE_MESSAGE_CLASSES as $class) {
                if (is_a($exception, $class)) {
                    return preg_match(self::CONSOLE_USAGE_MESSAGE, ltrim($exception->getMessage())) === 1;
                }
            }
            return false;
        }
        return preg_match(self::CONSOLE_USAGE_MESSAGE, ltrim($message)) === 1
            && preg_match(self::CONSOLE_USAGE_FRAME, $message) === 1;
    }

    private function previousChain(\Throwable $exception): array
    {
        $chain = [];
        $previous = $exception->getPrevious();
        while ($previous !== null && count($chain) < self::MAX_PREVIOUS) {
            $chain[] = get_class($previous) . ': ' . mb_substr($previous->getMessage(), 0, 300)
                . ' (' . $previous->getFile() . ':' . $previous->getLine() . ')';
            $previous = $previous->getPrevious();
        }
        return $chain;
    }

    private function serverValue(string $key): ?string
    {
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        return null;
    }

    private function stripTraceArguments(string $stack): string
    {
        $result = preg_replace(
            '/^(#\d+\s+(?:.+?\(\d+\)|\[internal function\]):\s+[^(\r\n]+)\(.*\)[ \t]*$/m',
            '$1()',
            $stack
        );
        return $result ?? $stack;
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
