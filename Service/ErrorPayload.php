<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Service;

class ErrorPayload
{
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
