<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

class PathMapper
{
    public const LINE_NOTE = 'Line numbers of minified or preprocessed templates are not reliable.';

    private const PREPROCESSED = '~^(.*?/)?var/view_preprocessed/pub/static/~i';

    public function isPreprocessed(?string $file): bool
    {
        return $file !== null && $file !== '' && preg_match(self::PREPROCESSED, $file) === 1;
    }

    public function toSource(?string $file): ?string
    {
        if ($file === null || $file === '') {
            return $file;
        }
        $mapped = preg_replace(self::PREPROCESSED, '$1', $file);
        return is_string($mapped) && $mapped !== '' ? $mapped : $file;
    }

    public function isLineUnreliable(?string $file, ?int $line = null): bool
    {
        if ($file === null || $file === '') {
            return false;
        }
        if ($this->isPreprocessed($file)) {
            return true;
        }
        $path = strtolower((string)preg_replace('/[?#].*$/', '', $file));
        $path = (string)preg_replace('/(?::\d+){1,2}$/', '', $path);
        if (preg_match('/\.min\.(?:js|css)$/', $path) === 1) {
            return true;
        }
        return str_ends_with($path, '.phtml') && $line !== null && $line <= 1;
    }
}
