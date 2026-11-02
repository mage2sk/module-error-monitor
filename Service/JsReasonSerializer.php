<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

class JsReasonSerializer
{
    public const MAX_LENGTH = 500;

    public const UNKNOWN_TYPE = 'unknown';

    private const TEXT_KEYS = ['message', 'responseText', 'statusText'];

    private const REASON_TYPE_PATTERN = '/^[A-Za-z]+(?::[\w$.]{0,50})?$/';

    private const SENSITIVE_KEY = '/pass|pwd|token|secret|key|authori[sz]ation|auth|cookie|card|cvv|cvc|email|session/i';

    private const REDACTED = 'redacted';

    private const OBJECT_PLACEHOLDER = '/^\s*(?:uncaught\s+)?(?:[A-Za-z]*Error:\s*)?\[object [A-Za-z]+\]\s*$/i';

    public function describe(mixed $reason, int $max = self::MAX_LENGTH): string
    {
        if ($reason === null) {
            return '';
        }
        if (is_bool($reason)) {
            return $reason ? 'true' : 'false';
        }
        if (is_scalar($reason)) {
            return $this->truncate(trim((string)$reason), $max);
        }
        if ($reason === []) {
            return '';
        }
        if (is_array($reason)) {
            foreach (self::TEXT_KEYS as $key) {
                $value = $reason[$key] ?? null;
                if (is_scalar($value) && trim((string)$value) !== '') {
                    return $this->truncate(trim((string)$value), $max);
                }
            }
            $json = json_encode(
                $this->redact($reason),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                16
            );
            if (is_string($json) && $json !== '' && $json !== '[]' && $json !== '{}') {
                return $this->truncate($json, $max);
            }
            return '[object Object]';
        }
        return '';
    }

    public function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) && $value !== [] ? 'array' : 'object',
            default => 'unknown',
        };
    }

    public function isObjectPlaceholder(string $message): bool
    {
        return preg_match(self::OBJECT_PLACEHOLDER, $message) === 1;
    }

    public function resolveMessage(array $payload): array
    {
        $raw = $payload['message'] ?? '';
        $type = isset($payload['reasonType']) && is_scalar($payload['reasonType'])
            ? $this->sanitizeType((string)$payload['reasonType'])
            : '';

        if (is_array($raw)) {
            return [$this->describe($raw), $type !== '' ? $type : $this->typeOf($raw)];
        }

        $message = is_scalar($raw) ? trim((string)$raw) : '';
        $reason = $payload['reason'] ?? null;
        if (($message === '' || $this->isObjectPlaceholder($message)) && is_array($reason)) {
            $described = $this->describe($reason);
            if ($described !== '') {
                return [$described, $type !== '' ? $type : $this->typeOf($reason)];
            }
        }
        return [$message, $type];
    }

    public function sanitizeType(string $type): string
    {
        $type = mb_substr(trim($type), 0, 64);
        if ($type === '') {
            return '';
        }
        return preg_match(self::REASON_TYPE_PATTERN, $type) === 1 ? $type : self::UNKNOWN_TYPE;
    }

    public function redact(array $value, int $depth = 0): array
    {
        if ($depth > 16) {
            return [];
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $value[$key] = self::REDACTED;
            } elseif (is_array($item)) {
                $value[$key] = $this->redact($item, $depth + 1);
            }
        }
        return $value;
    }

    private function truncate(string $value, int $max): string
    {
        if ($max <= 0 || mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max, 'UTF-8');
    }
}
