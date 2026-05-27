<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Storefront block that renders the JS error collector. Emits nothing at all
 * when capture is disabled, so there is zero footprint on stores that don't
 * use it.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Block\Js;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\ErrorMonitor\Helper\Config;

class Beacon extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isCaptureEnabled(): bool
    {
        try {
            return $this->config->isJsCaptureEnabled((int)$this->_storeManager->getStore()->getId());
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getCollectUrl(): string
    {
        return $this->getUrl('panth_errormonitor/js/collect', ['_secure' => $this->getRequest()->isSecure()]);
    }

    public function getSampleRate(): int
    {
        return $this->config->getJsSampleRate((int)$this->_storeManager->getStore()->getId());
    }

    /**
     * CSP-safe config payload, emitted as inert JSON (never executed).
     */
    public function getConfigJson(): string
    {
        return (string)json_encode([
            'url' => $this->getCollectUrl(),
            'sampleRate' => $this->getSampleRate(),
            'maxPerPage' => 10,
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
