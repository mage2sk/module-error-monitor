<?php
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
            return $result;
        }

        return $result;
    }

    private function isSameOrigin(): bool
    {
        $candidate = $this->hostFromUrl($this->serverValue('HTTP_ORIGIN'))
            ?? $this->hostFromUrl($this->serverValue('HTTP_REFERER'));
        if ($candidate === null) {
            return false;
        }
        return in_array($candidate, $this->getAllowedHosts(), true);
    }

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
        return true;
    }
}
