<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Produces a stable fingerprint for an error so that repeated occurrences
 * collapse into one group. Variable parts (numbers, hex ids, quoted values,
 * absolute paths, query strings, line/column numbers) are normalised out so
 * that e.g. "Product 123 not found" and "Product 456 not found" group
 * together instead of flooding the table with near-duplicates.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

class Fingerprinter
{
    /**
     * @param string      $source    php|js
     * @param string      $type      Exception class / JS error name
     * @param string      $message   Raw message
     * @param string|null $file      File path / JS source URL
     * @param int|null    $line      Line number
     */
    public function fingerprint(
        string $source,
        string $type,
        string $message,
        ?string $file = null,
        ?int $line = null
    ): string {
        $normalisedMessage = $this->normalizeMessage($message);
        $normalisedFile = $this->normalizeFile((string)$file);

        // Line is intentionally excluded for JS (minified bundles shift lines
        // between deploys); kept loosely for PHP via the normalised file.
        $parts = [
            $source,
            mb_strtolower(trim($type)),
            $normalisedMessage,
            $normalisedFile,
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Collapse variable tokens so similar messages share a fingerprint.
     */
    public function normalizeMessage(string $message): string
    {
        $msg = $message;

        // Drop everything after a PHP stack-trace marker — that part is noise
        // for grouping (the trace itself is stored on the event).
        $cut = stripos($msg, 'Stack trace:');
        if ($cut !== false) {
            $msg = substr($msg, 0, $cut);
        }

        $msg = mb_strtolower($msg);

        $patterns = [
            '/0x[0-9a-f]+/i'                              => '<hex>',   // hex ids/pointers
            '~(/[^\s"\'<>]+){2,}~'                        => '<path>',  // absolute paths
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '<uuid>',
            '/\b\d+\b/'                                    => '<n>',     // bare numbers
            '/"[^"]*"/'                                    => '"<v>"',   // quoted strings
            "/'[^']*'/"                                    => "'<v>'",
        ];
        foreach ($patterns as $pattern => $replacement) {
            $msg = (string)preg_replace($pattern, $replacement, $msg);
        }

        $msg = trim((string)preg_replace('/\s+/', ' ', $msg));

        // Cap length so an enormous message can't blow up the hash input.
        return mb_substr($msg, 0, 500);
    }

    /**
     * Strip scheme/host/query from a JS source URL and trailing line:col so
     * the same script groups regardless of CDN host or cache-busting query.
     */
    private function normalizeFile(string $file): string
    {
        if ($file === '') {
            return '';
        }
        // Remove query string and fragment.
        $file = preg_replace('/[?#].*$/', '', $file) ?? $file;
        // Strip protocol + host for absolute URLs.
        $file = preg_replace('~^https?://[^/]+~i', '', $file) ?? $file;
        // Strip trailing :line:col appended by some browsers.
        $file = preg_replace('/:\d+(:\d+)?$/', '', $file) ?? $file;
        return mb_strtolower(mb_substr(trim($file), 0, 255));
    }
}
