<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Produces a stable fingerprint for an error so repeated occurrences collapse
 * into one group. Two responsibilities:
 *
 *   1. extractType()      — try to mine the real exception/error class out of
 *                           a raw log message BEFORE normalisation destroys
 *                           the discriminating tokens. When a record is logged
 *                           without a Throwable in context, the channel name
 *                           ("main") is useless — extractType recovers the
 *                           proper FQN / family from the message itself.
 *
 *   2. normalizeMessage() — collapse variable tokens (JSON blobs, URLs, file
 *                           paths, UUIDs, hex digests, session IDs, IPs,
 *                           line/position markers, quoted values, big numbers,
 *                           whitespace) so structurally identical errors share
 *                           one fingerprint. Rules are ordered specific-first
 *                           so a more general rule never gets a chance to eat
 *                           tokens a more specific rule should have collapsed.
 *
 * ReDoS safety: messages over 32 KiB are truncated before the normaliser
 * touches them, and every regex uses bounded repetition or anchored quantifiers
 * so worst-case is linear.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

class Fingerprinter
{
    private const MAX_NORMALISE_INPUT = 32768;

    private const MAX_FINGERPRINT_INPUT = 500;

    /**
     * Type-extraction candidates tried IN ORDER against the RAW message
     * (case-sensitive, BEFORE normalisation). First match wins; the captured
     * group is the proposed type.
     *
     * @var array<int, array{0: string, 1: int}>  pairs of [regex, captureGroup]
     */
    private const TYPE_PATTERNS = [
        // 1. Canonical PHP/Magento wrapper: exception 'Foo\Bar\Baz' with message '...'
        ['/^exception [\'"]([A-Za-z_][A-Za-z0-9_\\\\]*)[\'"] with message/', 1],

        // 2. Variant: 'Foo\Bar\Baz Exception: ...' (Exception literal separated by a space)
        ['/^([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)\s+Exception:\s+/', 1],

        // 3. Generic 'SomeClass\Name(Exception|Error|Throwable)[: |with message]' prefix.
        ['/^([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*(?:Exception|Error|Throwable))(?::\s+|\s+with\s+message\b)/', 1],

        // 4. Custom trace-dump first frame: 'Exception Trace: #0 file(N): Class->method()'.
        ['/^Exception Trace:\s*#0\s+\S+(?:\(\d+\))?:\s+([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)(?:->|::)/', 1],

        // 5. Native JS error class prefix, optional "Uncaught ".
        ['/^(?:Uncaught\s+)?(TypeError|SyntaxError|ReferenceError|RangeError|URIError|EvalError|AggregateError|Error)\b/', 1],

        // 6. Magento template-engine wrapper exposing the responsible module.
        ['/^Invalid template file:\s*\'[^\']*\'\s+in module:\s*\'([^\']+)\'/', 1],

        // 7. Generic "[Tag] BLOCKED|ERROR|WARN|FATAL ..." log convention.
        ['/^\[([A-Za-z][A-Za-z0-9_]*)\]\s+(?:BLOCKED|ERROR|WARN|INFO|DEBUG|FATAL|CRITICAL)\b/', 1],

        // 8. Elasticsearch caused_by exception type (most specific layer).
        ['/"caused_by"\s*:\s*\{\s*"type"\s*:\s*"([a-z_]+)"/', 1],

        // 9. Elasticsearch root_cause exception type (fallback).
        ['/"error"\s*:\s*\{\s*"root_cause"\s*:\s*\[\s*\{\s*"type"\s*:\s*"([a-z_]+)"/', 1],

        // 10. Final fallback: PHP error-handler severity word at message start.
        ['/^(Warning|Notice|Deprecated(?: Functionality)?|Fatal error|Parse error|Strict [Ss]tandards)\b/', 1],
    ];

    /**
     * Identifier-shaped quoted tokens (module names, table names, column
     * names, config paths, ACL resources, cache types, event names, etc.) —
     * we KEEP these in the fingerprint so distinct identifiers don't collide,
     * but normalise the quote-style so 'Foo' and "Foo" share a fingerprint.
     * Anything else inside quotes is free text and gets collapsed to <v>.
     */
    private const IDENTIFIER_TOKEN = '/^[A-Za-z0-9_.\/:\\\\\-]{1,64}$/';

    /**
     * Ordered normalisation rules. Each entry is [pattern, replacement].
     * Specific-first ordering is critical — see class docblock.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const RULES = [
        // (Stage 0 — extract Elasticsearch `caused_by.type` / `root_cause.type`
        // and append them as a suffix BEFORE the JSON collapse runs — handled
        // in normalizeMessage() so the discriminator lives OUTSIDE the braces
        // that the JSON rule consumes.)

        // === Stage 1: structural payload collapse ===
        //
        // JSON-ish blob collapse done in repeated simple passes instead of one
        // monster regex: each pass replaces an innermost {…} or […] (no nested
        // braces inside) with the literal placeholder <json>; the placeholder
        // contains no braces, so the next pass collapses the next level up,
        // and so on. Six passes handle three levels of nesting. PCRE-safe and
        // ReDoS-bounded by the per-match length cap.

        ['/\{[^{}]{20,5000}\}/', '<json>'],
        ['/\[[^\[\]]{20,5000}\]/', '<json>'],
        ['/\{[^{}]{20,5000}\}/', '<json>'],
        ['/\[[^\[\]]{20,5000}\]/', '<json>'],
        ['/\{[^{}]{20,5000}\}/', '<json>'],
        ['/\[[^\[\]]{20,5000}\]/', '<json>'],

        // Fallback for truncated JSON tails (export truncation cuts mid-stream).
        ['/[\{\[]["\w:,\s\.\-\/]{200,}$/', '<json>'],

        // === Stage 2: URLs and paths (longest-match first) ===

        ['~https?://[^\s"\'<>)\],]+~', '<url>'],

        // Unix absolute paths under standard top-level dirs.
        ['~/(?:var/www|home|srv|opt|app|usr|tmp|etc|root|mnt|data)/[\w./%@+\-]+~', '<path>'],

        // Windows absolute paths (defensive — cheap, future-proof).
        ['~(?:[a-z]:\\\\|/[a-z]:/)[^\s"\'()<>,]+~', '<path>'],

        // Partial /pub/static or /pub/media references (post-truncate fragments).
        ['~/pub/(?:static|media|errors|opt)/[\w./%@+\-]+~', '<path>'],

        // Magento REST guest-cart masked-id tokens.
        ['~/rest/(?:default/)?v\d+/guest-carts/[a-z0-9]{20,40}~', '/rest/v1/guest-carts/<id>'],

        // Naked filenames with known extensions (after url/path ran).
        ['~(?<![a-z0-9_])[a-z0-9_\-]+(?:/[a-z0-9_\-]+)*\.(?:php|phtml|js|mjs|css|less|sass|scss|html|htm|xml|xsd|json|tpl|twig|map|sql|md|yml|yaml|sh|inc)(?:\((\d+)\))?~', '<file>'],

        // === Stage 3: identifiers (UUID → prefixed hex → bare hex → alnum → IP) ===

        ['/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/', '<uuid>'],

        ['/\bsha(?:1|256|384|512)=[0-9a-f]{32,128}\b/', 'sha=<hex>'],

        // GraphQL Report ID hex suffix (Magento 2 GraphQL pattern).
        ['/\bgraph-ql-[0-9a-f]{10,16}\b/', 'graph-ql-<id>'],

        // Catch-all for any remaining long hex digest (md5/sha/index uuid).
        ['/\b[0-9a-f]{32,}\b/', '<hex>'],

        // PHP session ID format.
        ['/\bsess_[a-z0-9]{20,}\b/', 'sess_<id>'],

        // IPv4 — `.` in lookbehind still blocks eating into version strings
        // like "v1.2.3.4", but `/` is removed so `tcp://1.2.3.4:3306` matches.
        ['~(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])~', '<ip>'],

        // IPv6 — matches any address with 3+ colon-separated hex groups,
        // covering both the fully-expanded form and `::` zero-compression
        // from any position. Group bodies allowed to be empty so the second
        // colon of `::` falls naturally into a zero-length [0-9a-f]{0,4}.
        ['/(?<![\w:])[0-9a-f]{0,4}(?::[0-9a-f]{0,4}){2,7}(?![\w:])/i', '<ip>'],

        // (Stage 4 — smart quoted-value collapse — handled separately in
        // normalizeMessage() so identifier-shaped tokens are preserved as
        // discriminators while free-text quoted spans become <v>.)

        // === Stage 5: position / line / offset markers ===

        ['/\b(?:on\s+line|near\s+line|line)\s+\d+(?:\s+column\s+\d+)?\b/', 'on line <line>'],

        ['/\bat\s+(?:position|offset|byte)\s*[:=]?\s*\d+\b/', 'at position <pos>'],

        ['/(?<![\w\/])(?:row|char(?:acter)?)\s+\d+\b/', 'row <pos>'],

        // "(NNN):" file-line tail.
        ['/\(\s*\d+\s*\):/', '(<line>):'],

        // "#N " stack-frame counter prefixes (#0, #1, ...).
        ['/(?m)^#\d+\s+/', '#<pos> '],

        // PHP TypeError "parameter #N".
        ['/\bparameter\s+#\d+\b/', 'parameter #<pos>'],

        // Version strings vX.Y[.Z[.W]].
        ['/\bv\d+(?:\.\d+){1,3}\b/', 'v<v>'],

        // === Stage 6: numeric collapse (LAST so it never eats parts of IDs) ===

        // Big bare numbers (likely entity ids).
        ['~(?<![\w/])\d{6,}(?![\w/])~', '<id>'],

        // All remaining bare numbers (small qtys, ids, prices, etc.).
        ['~(?<![\w/])\d+(?![\w/])~', '<n>'],

        // === Stage 7: whitespace + punctuation cleanups ===

        // Unicode whitespace variants → ordinary space.
        ['/[\t\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u', ' '],

        ['/\s{2,}/', ' '],

        // Trailing punctuation + leading quote/bracket noise.
        ['/[\s\.,;:!?\-–—]+$/u', ''],

        ['/^[\s>\|\-–—]+/u', ''],
    ];

    /**
     * Build a fingerprint string for one error occurrence.
     *
     * @param string      $source  php | js
     * @param string      $type    Exception class / JS error name (already chosen by caller)
     * @param string      $message Raw message
     * @param string|null $file    File path / JS source URL
     * @param int|null    $line    Line number (advisory — kept out of the hash for JS)
     */
    /**
     * Channel-ish "types" that aren't useful for grouping. When the caller
     * passes one of these we try to mine a sharper type out of the message
     * itself before building the hash, so different exception classes don't
     * collide just because they were logged through the same Monolog channel.
     */
    private const GENERIC_TYPES = ['', 'main', 'report', 'error', 'exception', 'throwable'];

    /**
     * JS messages whose only meaningful discriminator IS the message itself
     * (the property name, the missing identifier). These fire from countless
     * different .js files but represent the same defect family — keeping the
     * file in the fingerprint shatters them into dozens of micro-groups for
     * no triage benefit. When the JS message matches one of these patterns we
     * hash on (source, type, normalised_message) only and drop the file.
     *
     * Bounded quantifiers — ReDoS-safe.
     */
    private const FRAMEWORK_GENERIC_JS = [
        '/^cannot (?:read|set) propert(?:y|ies) of (?:null|undefined)\b/',
        '/^cannot read property [\'"][^\'"]{1,80}[\'"] of (?:null|undefined)\b/',
        '/^[\'"]?[a-z_$][\\w$.]{0,80}[\'"]? is not (?:a function|defined|iterable|an object)\b/',
        '/^undefined is not an? (?:object|function)\b/',
        '/^null is not an? (?:object|function)\b/',
        '/^cannot convert (?:undefined|null) to object\b/',
        '/^failed to execute [\'"][^\'"]{1,40}[\'"] on [\'"][^\'"]{1,40}[\'"]:/',
        '/^cannot convert a symbol value to a string\b/',
        '/^[\'"]?undefined[\'"]? is not valid json\b/',
        '/^the node before which the new node is to be inserted\b/',
    ];

    public function fingerprint(
        string $source,
        string $type,
        string $message,
        ?string $file = null,
        ?int $line = null
    ): string {
        $effectiveType = trim($type);
        if (in_array(strtolower($effectiveType), self::GENERIC_TYPES, true)) {
            $mined = $this->extractType($message);
            if ($mined !== null && $mined !== '') {
                $effectiveType = $mined;
            }
        }
        $normalisedMessage = $this->normalizeMessage($message);
        $parts = [
            $source,
            mb_strtolower($effectiveType),
            $normalisedMessage,
            $this->fingerprintFile($source, (string)$file, $normalisedMessage),
        ];
        return hash('sha256', implode('|', $parts));
    }

    /**
     * File component of the fingerprint. Three branches:
     *
     *   - JS + framework-generic message → empty string. The message + type
     *     IS the discriminator; the script that happened to host the error
     *     is noise (Magento serves the same generic JS error from dozens of
     *     RequireJS chunks and we don't want one bucket per chunk).
     *   - JS → basename only of the script path (after host/version/locale
     *     stripping). A CDN host change or a theme-path layout change must
     *     not split groups.
     *   - PHP → full normalised file path (precise, useful for triage).
     */
    private function fingerprintFile(string $source, string $file, string $normalisedMessage): string
    {
        if ($file === '') {
            return '';
        }
        if ($source !== 'js') {
            return $this->normalizeFile($file);
        }
        if ($this->isFrameworkGenericJs($normalisedMessage)) {
            return '';
        }
        $normalised = $this->normalizeFile($file);
        if ($normalised === '') {
            return '';
        }
        $slash = strrpos($normalised, '/');
        return $slash === false ? $normalised : substr($normalised, $slash + 1);
    }

    /**
     * True if the normalised JS message matches a known "framework-generic"
     * pattern whose source file adds no triage value.
     */
    public function isFrameworkGenericJs(string $normalisedMessage): bool
    {
        foreach (self::FRAMEWORK_GENERIC_JS as $pattern) {
            if (preg_match($pattern, $normalisedMessage) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Try to mine the real exception class / error family from the raw message.
     *
     * Returns the captured value (e.g. "Magento\Framework\Exception\LocalizedException"
     * or "TypeError") with length capped at 191. Returns null if no candidate
     * pattern matches — the caller should then fall back to channel/'error'.
     */
    public function extractType(string $message): ?string
    {
        $message = ltrim($message);
        if ($message === '') {
            return null;
        }
        foreach (self::TYPE_PATTERNS as [$pattern, $group]) {
            if (preg_match($pattern, $message, $m) && isset($m[$group])) {
                $captured = trim($m[$group]);
                if ($captured !== '') {
                    return mb_substr($captured, 0, 191);
                }
            }
        }
        return null;
    }

    /**
     * Short class name for an FQN (last backslash segment).
     */
    public function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    /**
     * Collapse variable tokens so similar messages share a fingerprint.
     */
    public function normalizeMessage(string $message): string
    {
        // Stage 0: pull out structured-error type discriminators (Elasticsearch
        // caused_by / root_cause) and APPEND them as a free-text suffix that
        // sits outside any {...} payload — the JSON collapse will otherwise
        // eat them and two different ES failures will share a fingerprint.
        $suffix = '';
        if (preg_match('/"caused_by"\s*:\s*\{\s*"type"\s*:\s*"([a-z_][a-z0-9_]*)"/i', $message, $m)) {
            $suffix .= ' caused_by=' . strtolower($m[1]);
        }
        if (preg_match('/"root_cause"\s*:\s*\[\s*\{\s*"type"\s*:\s*"([a-z_][a-z0-9_]*)"/i', $message, $m)) {
            $suffix .= ' root_cause=' . strtolower($m[1]);
        }

        // Strip stack-trace tail (case-insensitive) — done BEFORE lowercasing
        // so the marker matches as PHP writes it.
        $cut = stripos($message, 'Stack trace:');
        if ($cut !== false) {
            $message = substr($message, 0, $cut);
        }

        // Strip "next Foo\Bar\Baz:" exception-chain residue lines that survive
        // after the trace strip — must run while case is preserved so the
        // class-name anchor (\\) is unambiguous.
        $message = (string)preg_replace(
            '/(?im)^\s*next\s+(?:[a-z][a-z0-9_]*\\\\)+[a-z0-9_\\\\]+:?\s*$/',
            '',
            $message
        );

        // Hard size guard for ReDoS safety. The depth-limited JSON rule is
        // bounded, but bounding the input is cheaper insurance.
        if (strlen($message) > self::MAX_NORMALISE_INPUT) {
            $message = substr($message, 0, self::MAX_NORMALISE_INPUT);
        }

        $message = mb_strtolower($message);

        foreach (self::RULES as [$pattern, $replacement]) {
            $result = preg_replace($pattern, $replacement, $message);
            // preg_replace returns null on regex compile / runtime error. Skip
            // the rule rather than discarding the message.
            if ($result !== null) {
                $message = $result;
            }
        }

        // Smart quoted-value collapse (after the structural / identifier rules
        // have cleared out the easy cases). Identifier-shaped quoted tokens
        // (module / table / column / config-path / cache-type names) are KEPT
        // because they are the very thing that distinguishes one incident from
        // another; only free-text quoted spans collapse to <v>.
        $message = $this->collapseQuotedValues($message);

        // Append the survival-marker suffix (caused_by= / root_cause=) — sits
        // OUTSIDE any brace structure so the JSON pass never touches it.
        if ($suffix !== '') {
            $message .= $suffix;
        }

        $message = trim($message);
        return mb_substr($message, 0, self::MAX_FINGERPRINT_INPUT);
    }

    /**
     * Per-match replacement for quoted spans: keep identifier-shaped bodies,
     * collapse everything else.
     */
    private function collapseQuotedValues(string $message): string
    {
        $callback = function (array $m): string {
            // $m[0] is the full match including quotes; $m[1] is the body.
            $body = $m[1] ?? '';
            if ($body === '') {
                return $m[0];
            }
            if (preg_match(self::IDENTIFIER_TOKEN, $body)) {
                // Stable, quote-neutral form so 'Foo' and "Foo" share a fp.
                return "'" . $body . "'";
            }
            return '<v>';
        };
        $result = preg_replace_callback('/\'([^\']{1,200})\'/', $callback, $message);
        if ($result !== null) {
            $message = $result;
        }
        $result = preg_replace_callback('/"([^"]{1,200})"/', $callback, $message);
        return $result ?? $message;
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
        // Drop query / fragment first.
        $file = preg_replace('/[?#].*$/', '', $file) ?? $file;
        // Strip scheme + host (cdn1.example.com vs cdn2... shouldn't split groups).
        $file = preg_replace('~^https?://[^/]+~i', '', $file) ?? $file;
        // Strip Magento's static-asset cache-buster + area/theme/locale prefix
        // so the same script groups across every deploy and every locale,
        // e.g. "/pub/static/version1730000000/frontend/Magento/luma/en_US/mage/menu.js"
        //  -> "mage/menu.js"
        $file = preg_replace(
            '~^/?(?:pub/)?static/(?:version[0-9_]+/)?(?:adminhtml|frontend)/[^/]+/[^/]+/[a-z_]+/~i',
            '',
            $file
        ) ?? $file;
        // Trailing :line[:col] tail.
        $file = preg_replace('/:\d+(:\d+)?$/', '', $file) ?? $file;
        return mb_strtolower(mb_substr(trim($file), 0, 255));
    }
}
