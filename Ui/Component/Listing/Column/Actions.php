<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Builds the per-row action links (View / Resolve / Ignore / Delete) for the
 * error grid.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Ui\Component\Listing\Column;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Actions extends Column
{
    private const URL_VIEW = 'panth_errormonitor/error/view';
    private const URL_RESOLVE = 'panth_errormonitor/error/resolve';
    private const URL_IGNORE = 'panth_errormonitor/error/ignore';
    private const URL_DELETE = 'panth_errormonitor/error/delete';

    /**
     * Backend URL builder injected explicitly — Magento\Framework\UrlInterface
     * may resolve to the frontend builder inside the mui/index/render data
     * provider context and would omit the admin secret key, sending the link
     * to the storefront login redirect instead of the admin View page.
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly BackendUrl $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['group_id'])) {
                continue;
            }
            $id = (int)$item['group_id'];
            $item[$name]['view'] = [
                'href' => $this->urlBuilder->getUrl(self::URL_VIEW, ['group_id' => $id]),
                'label' => __('View'),
            ];
            $item[$name]['resolve'] = [
                'href' => $this->urlBuilder->getUrl(self::URL_RESOLVE, ['group_id' => $id]),
                'label' => __('Resolve'),
            ];
            $item[$name]['ignore'] = [
                'href' => $this->urlBuilder->getUrl(self::URL_IGNORE, ['group_id' => $id]),
                'label' => __('Ignore'),
            ];
            $item[$name]['delete'] = [
                'href' => $this->urlBuilder->getUrl(self::URL_DELETE, ['group_id' => $id]),
                'label' => __('Delete'),
                'confirm' => [
                    'title' => __('Delete error'),
                    'message' => __('Delete this error group and all its recorded occurrences?'),
                ],
            ];
        }

        return $dataSource;
    }
}
