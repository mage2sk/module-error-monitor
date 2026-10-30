<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

class BotDetector
{
    public const XML_IGNORE_BOTS = 'panth_errormonitor/filters/ignore_bots';

    public const XML_BOT_PATTERNS = 'panth_errormonitor/filters/bot_user_agents';

    public const DEFAULT_PATTERNS = [
        'bot',
        'crawl',
        'spider',
        'slurp',
        'bingbot',
        'baiduspider',
        'sogou',
        'dotbot',
        'ahrefs',
        'semrush',
        'yandex',
        'petal',
        'headless',
        'probe',
        'preview',
        'facebookexternalhit',
        'facebookcatalog',
        'Google-Read-Aloud',
    ];

    public const BOT_WORD_REGEX = '~(?<![a-z])bot(?![a-z])|[a-z]bot(?=[/;)\-]|$)~i';

    private const LITERAL = '~^[A-Za-z0-9 _./-]+$~';

    private static bool $warnedInvalid = false;

    private ?array $compiled = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function shouldIgnore(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_IGNORE_BOTS);
    }

    public function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return false;
        }
        if ($this->compiled === null) {
            $this->compiled = $this->compileConfigured();
        }
        return self::matchCompiled($this->compiled, $userAgent);
    }

    public function patterns(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_BOT_PATTERNS);
        if ($raw === null) {
            return self::DEFAULT_PATTERNS;
        }
        return self::splitLines((string)$raw);
    }

    public static function matches(?string $userAgent, array $patterns): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return false;
        }
        return self::matchCompiled(self::compileList($patterns), $userAgent);
    }

    public static function splitLines(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    public static function compile(array $patterns): ?string
    {
        $list = self::compileList($patterns);
        return $list === [] ? null : $list[0];
    }

    public static function compileList(array $patterns): array
    {
        $literals = [];
        $regexes = [];
        foreach ($patterns as $pattern) {
            $pattern = trim((string)$pattern);
            if ($pattern === '') {
                continue;
            }
            if (strtolower($pattern) === 'bot') {
                $regexes[] = self::BOT_WORD_REGEX;
                continue;
            }
            if (preg_match(self::LITERAL, $pattern) === 1) {
                $literals[] = preg_quote($pattern, '~');
                continue;
            }
            $candidate = '~' . str_replace('~', '\~', $pattern) . '~i';
            if (self::isValidRegex($candidate)) {
                $regexes[] = $candidate;
            }
        }
        if ($literals !== []) {
            array_unshift($regexes, '~' . implode('|', $literals) . '~i');
        }
        return $regexes;
    }

    public static function isValidRegex(string $regex): bool
    {
        return self::safeMatch($regex, '') !== null;
    }

    public static function safeMatch(string $regex, string $subject): ?bool
    {
        $failed = false;
        set_error_handler(static function () use (&$failed): bool {
            $failed = true;
            return true;
        });
        try {
            $result = preg_match($regex, $subject);
        } catch (\Throwable $e) {
            $failed = true;
            $result = false;
        } finally {
            restore_error_handler();
        }
        if ($failed || $result === false) {
            return null;
        }
        return $result === 1;
    }

    private static function matchCompiled(array $regexes, string $userAgent): bool
    {
        foreach ($regexes as $regex) {
            if (self::safeMatch($regex, $userAgent) === true) {
                return true;
            }
        }
        return false;
    }

    private function compileConfigured(): array
    {
        $patterns = $this->patterns();
        $compiled = self::compileList($patterns);
        if ($compiled === [] && $patterns !== []) {
            $this->warnInvalidOnce();
            return self::compileList(self::DEFAULT_PATTERNS);
        }
        return $compiled;
    }

    private function warnInvalidOnce(): void
    {
        if (self::$warnedInvalid || $this->logger === null) {
            return;
        }
        self::$warnedInvalid = true;
        try {
            $this->logger->warning(
                ErrorRecorder::INTERNAL_MARKER . ' Every bot user-agent pattern in the configuration is invalid;'
                . ' the default bot list is used instead.'
            );
        } catch (\Throwable $e) {
            return;
        }
    }
}
