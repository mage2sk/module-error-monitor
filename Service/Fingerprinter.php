<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

class Fingerprinter
{
    private const MAX_NORMALISE_INPUT = 32768;

    private const MAX_FINGERPRINT_INPUT = 500;

    private const TYPE_PATTERNS = [

        ['/^exception [\'"]([A-Za-z_][A-Za-z0-9_\\\\]*)[\'"] with message/', 1],

        ['/^([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)\s+Exception:\s+/', 1],

        ['/^([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*(?:Exception|Error|Throwable))(?::\s+|\s+with\s+message\b)/', 1],

        ['/^Exception Trace:\s*#0\s+\S+(?:\(\d+\))?:\s+([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)(?:->|::)/', 1],

        ['/^(?:Uncaught\s+)?(TypeError|SyntaxError|ReferenceError|RangeError|URIError|EvalError|AggregateError|Error)\b/', 1],

        ['/^Invalid template file:\s*\'[^\']*\'\s+in module:\s*\'([^\']+)\'/', 1],

        ['/^\[([A-Za-z][A-Za-z0-9_]*)\]\s+(?:BLOCKED|ERROR|WARN|INFO|DEBUG|FATAL|CRITICAL)\b/', 1],

        ['/"caused_by"\s*:\s*\{\s*"type"\s*:\s*"([a-z_]+)"/', 1],

        ['/"error"\s*:\s*\{\s*"root_cause"\s*:\s*\[\s*\{\s*"type"\s*:\s*"([a-z_]+)"/', 1],

        ['/^(Warning|Notice|Deprecated(?: Functionality)?|Fatal error|Parse error|Strict [Ss]tandards)\b/', 1],
    ];

    private const IDENTIFIER_TOKEN = '/^[A-Za-z0-9_.\/:\\\\\-]{1,64}$/';

    private const RULES = [

        ['/\{[^{}]{20,5000}\}/', '<json>'],
        ['/\[[^\[\]]{20,5000}\]/', '<json>'],
        ['/\{[^{}]{20,5000}\}/', '<json>'],
        ['/\[[^\[\]]{20,5000}\]/', '<json>'],
        ['/\{[^{}]{20,5000}\}/', '<json>'],
        ['/\[[^\[\]]{20,5000}\]/', '<json>'],

        ['/[\{\[]["\w:,\s\.\-\/]{200,}$/', '<json>'],

        ['~https?://[^\s"\'<>)\],]+~', '<url>'],

        ['~/(?:var/www|home|srv|opt|app|usr|tmp|etc|root|mnt|data)/[\w./%@+\-]+~', '<path>'],

        ['~(?:[a-z]:\\\\|/[a-z]:/)[^\s"\'()<>,]+~', '<path>'],

        ['~/pub/(?:static|media|errors|opt)/[\w./%@+\-]+~', '<path>'],

        ['~/rest/(?:default/)?v\d+/guest-carts/[a-z0-9]{20,40}~', '/rest/v1/guest-carts/<id>'],

        ['~(?<![a-z0-9_])[a-z0-9_\-]+(?:/[a-z0-9_\-]+)*\.(?:php|phtml|js|mjs|css|less|sass|scss|html|htm|xml|xsd|json|tpl|twig|map|sql|md|yml|yaml|sh|inc)(?:\((\d+)\))?~', '<file>'],

        ['/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/', '<uuid>'],

        ['/\bsha(?:1|256|384|512)=[0-9a-f]{32,128}\b/', 'sha=<hex>'],

        ['/\bgraph-ql-[0-9a-f]{10,16}\b/', 'graph-ql-<id>'],

        ['/\b[0-9a-f]{32,}\b/', '<hex>'],

        ['/\bsess_[a-z0-9]{20,}\b/', 'sess_<id>'],

        ['~(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])~', '<ip>'],

        ['/(?<![\w:])[0-9a-f]{0,4}(?::[0-9a-f]{0,4}){2,7}(?![\w:])/i', '<ip>'],

        ['/\b(?:on\s+line|near\s+line|line)\s+\d+(?:\s+column\s+\d+)?\b/', 'on line <line>'],

        ['/\bat\s+(?:position|offset|byte)\s*[:=]?\s*\d+\b/', 'at position <pos>'],

        ['/(?<![\w\/])(?:row|char(?:acter)?)\s+\d+\b/', 'row <pos>'],

        ['/\(\s*\d+\s*\):/', '(<line>):'],

        ['/(?m)^#\d+\s+/', '#<pos> '],

        ['/\bparameter\s+#\d+\b/', 'parameter #<pos>'],

        ['/\bv\d+(?:\.\d+){1,3}\b/', 'v<v>'],

        ['~(?<![\w/])\d{6,}(?![\w/])~', '<id>'],

        ['~(?<![\w/])\d+(?![\w/])~', '<n>'],

        ['/[\t\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u', ' '],

        ['/\s{2,}/', ' '],

        ['/[\s\.,;:!?\-–—]+$/u', ''],

        ['/^[\s>\|\-–—]+/u', ''],
    ];

    private const GENERIC_TYPES = ['', 'main', 'report', 'error', 'exception', 'throwable'];

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

    public function isFrameworkGenericJs(string $normalisedMessage): bool
    {
        foreach (self::FRAMEWORK_GENERIC_JS as $pattern) {
            if (preg_match($pattern, $normalisedMessage) === 1) {
                return true;
            }
        }
        return false;
    }

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

    public function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    public function normalizeMessage(string $message): string
    {
        $suffix = '';
        if (preg_match('/"caused_by"\s*:\s*\{\s*"type"\s*:\s*"([a-z_][a-z0-9_]*)"/i', $message, $m)) {
            $suffix .= ' caused_by=' . strtolower($m[1]);
        }
        if (preg_match('/"root_cause"\s*:\s*\[\s*\{\s*"type"\s*:\s*"([a-z_][a-z0-9_]*)"/i', $message, $m)) {
            $suffix .= ' root_cause=' . strtolower($m[1]);
        }

        $cut = stripos($message, 'Stack trace:');
        if ($cut !== false) {
            $message = substr($message, 0, $cut);
        }

        $message = (string)preg_replace(
            '/(?im)^\s*next\s+(?:[a-z][a-z0-9_]*\\\\)+[a-z0-9_\\\\]+:?\s*$/',
            '',
            $message
        );

        if (strlen($message) > self::MAX_NORMALISE_INPUT) {
            $message = substr($message, 0, self::MAX_NORMALISE_INPUT);
        }

        $message = mb_strtolower($message);

        foreach (self::RULES as [$pattern, $replacement]) {
            $result = preg_replace($pattern, $replacement, $message);

            if ($result !== null) {
                $message = $result;
            }
        }

        $message = $this->collapseQuotedValues($message);

        if ($suffix !== '') {
            $message .= $suffix;
        }

        $message = trim($message);
        return mb_substr($message, 0, self::MAX_FINGERPRINT_INPUT);
    }

    private function collapseQuotedValues(string $message): string
    {
        $callback = function (array $m): string {
            $body = $m[1] ?? '';
            if ($body === '') {
                return $m[0];
            }
            if (preg_match(self::IDENTIFIER_TOKEN, $body)) {
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

    private function normalizeFile(string $file): string
    {
        if ($file === '') {
            return '';
        }

        $file = preg_replace('/[?#].*$/', '', $file) ?? $file;

        $file = preg_replace('~^https?://[^/]+~i', '', $file) ?? $file;

        $file = preg_replace(
            '~^/?(?:pub/)?static/(?:version[0-9_]+/)?(?:adminhtml|frontend)/[^/]+/[^/]+/[a-z_]+/~i',
            '',
            $file
        ) ?? $file;

        $file = preg_replace('/:\d+(:\d+)?$/', '', $file) ?? $file;
        return mb_strtolower(mb_substr(trim($file), 0, 255));
    }
}
