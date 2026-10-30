<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;

class NoiseFilter
{
    public const XML_ENABLED = 'panth_errormonitor/filters/noise_enabled';

    public const XML_PATTERNS = 'panth_errormonitor/filters/noise_patterns';

    public const XML_IGNORE_PATTERNS = 'panth_errormonitor/general/ignore_patterns';

    public const XML_LEGACY_IGNORE_PATTERNS = 'panth_errormonitor/js_capture/ignore_patterns';

    public const DEFAULT_RULES = [
        'Object Not Found Matching Id:\d+, MethodName:\w+, ParamCount:\d+',
        'source:customer-data|js\.storage|jquery[.-]storageapi && message:'
            . 'storage\.remove|delete tmp\[a\[i\]\]|QuotaExceededError|'
            . '\(reading \'remove\'\)|\'remove\' of (?:undefined|null)|evaluating \'[\w$.]*remove\'|'
            . 'SecurityError|operation is insecure|localStorage|sessionStorage',
        'nostack:^(?:Uncaught\s+)?Script error\.?$',
        'any:(?:chrome|moz|safari|safari-web|ms-browser)-extension://',
    ];

    private const SCOPES = ['message', 'file', 'stack', 'source', 'any', 'nostack'];

    private ?array $rules = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED);
    }

    public function isIgnored(
        string $message,
        ?string $file,
        string $type = '',
        ?string $stack = null,
        ?bool $hasStack = null
    ): bool {
        if ($this->matchesIgnoreList($message, $file, $type, $stack)) {
            return true;
        }
        return $this->isEnabled() && $this->matchesNoise($message, $file, $stack, $hasStack);
    }

    public function matchesIgnoreList(string $message, ?string $file, string $type = '', ?string $stack = null): bool
    {
        $raw = (string)$this->scopeConfig->getValue(self::XML_IGNORE_PATTERNS);
        if ($raw === '') {
            $raw = (string)$this->scopeConfig->getValue(self::XML_LEGACY_IGNORE_PATTERNS);
        }
        if ($raw === '') {
            return false;
        }
        $haystack = mb_strtolower($message . "\n" . ($file ?? '') . "\n" . $type . "\n" . ($stack ?? ''));
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && str_contains($haystack, mb_strtolower($line))) {
                return true;
            }
        }
        return false;
    }

    public function matchesNoise(string $message, ?string $file, ?string $stack = null, ?bool $hasStack = null): bool
    {
        if ($this->rules === null) {
            $this->rules = self::parseRules($this->rawRules());
        }
        return self::matchRules($this->rules, $message, $file, $stack, $hasStack);
    }

    public static function matchRules(
        array $rules,
        string $message,
        ?string $file,
        ?string $stack = null,
        ?bool $hasStack = null
    ): bool {
        foreach ($rules as $conditions) {
            $all = true;
            foreach ($conditions as [$scope, $regex]) {
                if (!self::matchCondition($scope, $regex, $message, (string)$file, (string)$stack, $hasStack)) {
                    $all = false;
                    break;
                }
            }
            if ($all && $conditions !== []) {
                return true;
            }
        }
        return false;
    }

    public static function parseRules(array $lines): array
    {
        $rules = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $conditions = [];
            $valid = true;
            foreach (explode(' && ', $line) as $part) {
                $part = trim($part);
                $scope = 'message';
                if (preg_match('/^(' . implode('|', self::SCOPES) . '):(.*)$/s', $part, $m) === 1) {
                    $scope = $m[1];
                    $part = $m[2];
                }
                $regex = '~' . str_replace('~', '\~', $part) . '~i';
                if ($part === '' || !BotDetector::isValidRegex($regex)) {
                    $valid = false;
                    break;
                }
                $conditions[] = [$scope, $regex];
            }
            if ($valid && $conditions !== []) {
                $rules[] = $conditions;
            }
        }
        return $rules;
    }

    private static function matchCondition(
        string $scope,
        string $regex,
        string $message,
        string $file,
        string $stack,
        ?bool $hasStack
    ): bool {
        return match ($scope) {
            'file' => $file !== '' && self::test($regex, $file),
            'stack' => $stack !== '' && self::test($regex, $stack),
            'source' => ($file !== '' && self::test($regex, $file)) || ($stack !== '' && self::test($regex, $stack)),
            'any' => self::test($regex, $message . "\n" . $file . "\n" . $stack),
            'nostack' => ($hasStack !== null ? !$hasStack : trim($stack) === '')
                && self::test($regex, trim($message)),
            default => self::test($regex, $message),
        };
    }

    private static function test(string $regex, string $subject): bool
    {
        return BotDetector::safeMatch($regex, $subject) === true;
    }

    private function rawRules(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATTERNS);
        if ($raw === null) {
            return self::DEFAULT_RULES;
        }
        return BotDetector::splitLines((string)$raw);
    }
}
