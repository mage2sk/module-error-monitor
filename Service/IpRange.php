<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

class IpRange
{
    private const V4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    public static function parseList(array $lines): array
    {
        $ranges = [];
        foreach ($lines as $line) {
            $range = self::parse((string)$line);
            if ($range !== null) {
                $ranges[] = $range;
            }
        }
        return $ranges;
    }

    public static function invalidLines(array $lines): array
    {
        $invalid = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if (self::stripComment($line) !== '' && self::parse($line) === null) {
                $invalid[] = $line;
            }
        }
        return $invalid;
    }

    public static function stripComment(string $line): string
    {
        $pos = strpos($line, '#');
        return trim($pos === false ? $line : substr($line, 0, $pos));
    }

    public static function parse(string $line): ?array
    {
        $line = self::stripComment($line);
        if ($line === '') {
            return null;
        }
        $parts = explode('/', $line);
        if (count($parts) > 2) {
            return null;
        }
        $packed = self::pack($parts[0]);
        if ($packed === null) {
            return null;
        }
        $maxBits = strlen($packed) * 8;
        $mapped = $maxBits === 32 && str_contains($parts[0], ':');
        $bits = $maxBits;
        if (isset($parts[1])) {
            if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1) {
                return null;
            }
            $bits = (int)$parts[1];
            if ($mapped) {
                if ($bits < 96 || $bits > 128) {
                    return null;
                }
                $bits -= 96;
            } elseif ($bits > $maxBits) {
                return null;
            }
        }
        return [self::applyMask($packed, $bits), $bits];
    }

    public static function contains(array $ranges, ?string $ip): bool
    {
        if ($ip === null || $ranges === []) {
            return false;
        }
        $packed = self::pack($ip);
        if ($packed === null) {
            return false;
        }
        foreach ($ranges as [$network, $bits]) {
            if (strlen($network) === strlen($packed) && self::applyMask($packed, $bits) === $network) {
                return true;
            }
        }
        return false;
    }

    public static function isValidIp(?string $ip): bool
    {
        return $ip !== null && self::pack($ip) !== null;
    }

    private static function pack(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if (!is_string($packed)) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, self::V4_MAPPED_PREFIX)) {
            return substr($packed, 12);
        }
        return $packed;
    }

    private static function applyMask(string $packed, int $bits): string
    {
        $out = '';
        $length = strlen($packed);
        for ($i = 0; $i < $length; $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $out .= chr(ord($packed[$i]) & $mask);
        }
        return $out;
    }
}
