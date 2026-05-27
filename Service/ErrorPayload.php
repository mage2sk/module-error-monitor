<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Immutable, source-agnostic description of a single error occurrence.
 * Both the PHP log handler and the JS beacon controller build one of these
 * and hand it to ErrorRecorder — keeping the recorder free of any knowledge
 * about where the error came from.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

class ErrorPayload
{
    /**
     * @param string      $source     php|js
     * @param string      $severity   PSR-3 severity name
     * @param string      $type       Exception class / JS error name
     * @param string      $message    Human-readable message
     * @param string|null $file       Originating file / JS source URL
     * @param int|null    $line       Line number
     * @param string|null $stackTrace Stack trace text
     * @param string|null $url        Request URL / page
     * @param string|null $referer    HTTP referer
     * @param string|null $userAgent  Client user agent
     * @param string|null $ip         Client IP (already anonymised by caller if required)
     * @param string|null $httpMethod HTTP method
     * @param array<string, mixed> $context Extra context (colno, channel, etc.)
     * @param int         $storeId    Store view id (0 = unknown/global)
     */
    public function __construct(
        public readonly string $source,
        public readonly string $severity,
        public readonly string $type,
        public readonly string $message,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?string $stackTrace = null,
        public readonly ?string $url = null,
        public readonly ?string $referer = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $ip = null,
        public readonly ?string $httpMethod = null,
        public readonly array $context = [],
        public readonly int $storeId = 0
    ) {
    }
}
