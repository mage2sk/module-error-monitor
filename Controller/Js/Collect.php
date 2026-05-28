<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Public storefront endpoint that receives JS error beacons.
 *
 * CSRF is INTENTIONALLY bypassed (CsrfAwareActionInterface returns true) for
 * the same reason as other beacon endpoints in this suite: the page that
 * fires the beacon is usually served from Full Page Cache and has no fresh
 * form key, and minting one would defeat the cache. The endpoint is hardened
 * with several independent layers so the bypass is safe:
 *
 *   1. POST-only (HttpPostActionInterface).
 *   2. Same-origin enforcement — the Origin/Referer host MUST match a known
 *      store base URL host, or the request is dropped.
 *   3. Body size cap BEFORE json_decode (rejects oversized payloads).
 *   4. Per-IP + global rate limiting (RateLimiter).
 *   5. Strict field validation, length caps, control-char stripping.
 *   6. Ignore-list + dedupe in the recorder collapse noise into one row.
 *   7. The endpoint writes ONLY to the error tables — no session, customer,
 *      order or auth state is read or written.
 *   8. It NEVER echoes any input back (always 204), so it cannot be used for
 *      reflected XSS.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Controller\Js;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use Panth\ErrorMonitor\Service\RateLimiter;

class Collect implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_MESSAGE = 2000;
    private const MAX_TYPE = 191;
    private const MAX_SOURCE = 1024;
    private const MAX_STACK = 8000;

    /** @var string[]|null Lazily-built allowlist of store hostnames. */
    private ?array $allowedHosts = null;

    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly RateLimiter $rateLimiter,
        private readonly ErrorRecorder $recorder,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly StoreManagerInterface $storeManager,
        private readonly DeploymentGuard $deploymentGuard
    ) {
    }

    public function execute()
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(204);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();

            if (!$this->config->isJsCaptureEnabled($storeId)) {
                return $result;
            }
            // Pause-during-deploy: bot/visitor beacons while the site is being
            // deployed are noise, not real bugs.
            if ($this->deploymentGuard->isCaptureSuspended()) {
                return $result;
            }
            if (!$this->isSameOrigin()) {
                return $result;
            }

            $body = (string)$this->request->getContent();
            if ($body === '' || strlen($body) > $this->config->getJsMaxBodyBytes($storeId)) {
                return $result;
            }

            $ip = (string)$this->request->getClientIp();
            if (!$this->rateLimiter->allow($ip, $this->config->getJsRateLimitPerMinute($storeId))) {
                return $result;
            }

            $payload = json_decode($body, true);
            if (!is_array($payload)) {
                return $result;
            }

            $message = $this->str($payload['message'] ?? '', self::MAX_MESSAGE);
            if ($message === '') {
                return $result;
            }

            $type = $this->str($payload['name'] ?? 'Error', self::MAX_TYPE) ?: 'Error';
            $source = $this->str($payload['source'] ?? '', self::MAX_SOURCE);
            $stack = $this->str($payload['stack'] ?? '', self::MAX_STACK);
            $kind = $this->str($payload['kind'] ?? 'error', 32);
            $lineno = isset($payload['lineno']) ? (int)$payload['lineno'] : null;
            $colno = isset($payload['colno']) ? (int)$payload['colno'] : null;
            $pageUrl = $this->str($payload['pageUrl'] ?? '', 2048);

            $this->recorder->record(new ErrorPayload(
                source: ErrorGroup::SOURCE_JS,
                severity: 'error',
                type: $type,
                message: $message,
                file: $source !== '' ? $source : null,
                line: $lineno,
                stackTrace: $stack !== '' ? $stack : null,
                url: $pageUrl !== '' ? $pageUrl : ($this->serverValue('HTTP_REFERER') ?? null),
                referer: $this->serverValue('HTTP_REFERER'),
                userAgent: $this->serverValue('HTTP_USER_AGENT'),
                ip: $this->ipAnonymizer->process($ip ?: null, $storeId),
                httpMethod: 'POST',
                context: ['colno' => $colno, 'kind' => $kind, 'origin' => 'storefront-js'],
                storeId: $storeId
            ));
        } catch (\Throwable $e) {
            // Always answer 204; never surface internals.
            return $result;
        }

        return $result;
    }

    /**
     * Origin (preferred) or Referer host must match a known store host.
     */
    private function isSameOrigin(): bool
    {
        $candidate = $this->hostFromUrl($this->serverValue('HTTP_ORIGIN'))
            ?? $this->hostFromUrl($this->serverValue('HTTP_REFERER'));
        if ($candidate === null) {
            return false;
        }
        return in_array($candidate, $this->getAllowedHosts(), true);
    }

    /**
     * @return string[]
     */
    private function getAllowedHosts(): array
    {
        if ($this->allowedHosts !== null) {
            return $this->allowedHosts;
        }
        $hosts = [];
        foreach ($this->storeManager->getStores() as $store) {
            $urls = [
                $store->getBaseUrl(),
                $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK, true),
            ];
            foreach ($urls as $url) {
                $host = $this->hostFromUrl((string)$url);
                if ($host !== null) {
                    $hosts[$host] = true;
                }
            }
        }
        $this->allowedHosts = array_keys($hosts);
        return $this->allowedHosts;
    }

    private function hostFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function str(mixed $value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = (string)$value;
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $value = trim($value);
        return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') : $value;
    }

    private function serverValue(string $key): ?string
    {
        $value = $this->request->getServer($key);
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // See class docblock — bypass is compensated by same-origin checks,
        // rate limiting, strict validation, and a write surface limited to
        // the error tables.
        return true;
    }
}
