<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;

class GraphQlClientErrorFilter
{
    public const XML_MODE = 'panth_errormonitor/php_capture/graphql_client_errors';

    public const MODE_SKIP = 'skip';

    public const MODE_NOTICE = 'notice';

    public const MODE_RECORD = 'record';

    public const CONTEXT_FLAG = 'graphql_client_error';

    private const CLIENT_AWARE = 'GraphQL\Error\ClientAware';

    private const AGGREGATE = 'Magento\Framework\Exception\AggregateExceptionInterface';

    private const MAX_REGISTERED = 200;

    private const REPORT_WRAPPER = '/^Report ID: graph-ql-[0-9a-f]{6,32}; Message: /i';

    private const BARE_GRAPHQL_ERROR = '/^GraphQL\\\\Error\\\\Error: /';

    private static array $registered = [];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string)$this->scopeConfig->getValue(self::XML_MODE)));
        return in_array($mode, [self::MODE_SKIP, self::MODE_NOTICE, self::MODE_RECORD], true)
            ? $mode
            : self::MODE_SKIP;
    }

    public function register(iterable $errors): void
    {
        foreach ($errors as $error) {
            if (!$error instanceof \Throwable) {
                continue;
            }
            if ($this->isClientSafeThrowable($error) && trim($error->getMessage()) !== '') {
                $this->remember(trim($error->getMessage()));
            }
            $previous = $error->getPrevious();
            if ($previous !== null && is_a($previous, self::AGGREGATE) && method_exists($previous, 'getErrors')) {
                $this->register((array)$previous->getErrors());
            }
        }
    }

    public static function reset(): void
    {
        self::$registered = [];
    }

    public function isClientSafe(string $message, array $context = []): bool
    {
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            return $this->isClientSafeThrowable($exception);
        }
        $trimmed = (string)preg_replace(self::REPORT_WRAPPER, '', ltrim($message));
        foreach (self::$registered as $registered) {
            if (str_starts_with($trimmed, $registered) || str_contains($message, 'Error: ' . $registered)) {
                return true;
            }
        }
        return preg_match(self::BARE_GRAPHQL_ERROR, ltrim($message)) === 1
            && preg_match('/^Next /m', $message) !== 1;
    }

    public function isClientSafeThrowable(\Throwable $error): bool
    {
        if ($this->isReportWrapper($error)) {
            return $this->isClientSafeThrowable($error->getPrevious());
        }
        if (!interface_exists(self::CLIENT_AWARE) || !is_a($error, self::CLIENT_AWARE)) {
            return false;
        }
        try {
            return (bool)$error->isClientSafe();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isReportWrapper(\Throwable $error): bool
    {
        return $error->getPrevious() !== null
            && !is_a($error, self::CLIENT_AWARE)
            && preg_match(self::REPORT_WRAPPER, $error->getMessage()) === 1;
    }

    private function remember(string $message): void
    {
        if (isset(self::$registered[$message])) {
            return;
        }
        if (count(self::$registered) >= self::MAX_REGISTERED) {
            array_shift(self::$registered);
        }
        self::$registered[$message] = $message;
    }
}
