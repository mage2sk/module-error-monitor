<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Shared base for grid mass actions (resolve / ignore / delete). Honours the
 * grid's select-all / exclude filters via Magento\Ui\Component\MassAction\Filter.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Controller\Adminhtml\Error;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\Collection;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;

abstract class AbstractMassAction extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ErrorMonitor::manage';

    public function __construct(
        Context $context,
        protected readonly Filter $filter,
        protected readonly CollectionFactory $collectionFactory,
        protected readonly ErrorGroupResource $groupResource
    ) {
        parent::__construct($context);
    }

    /**
     * @return int Number of affected groups.
     */
    abstract protected function operate(Collection $collection): int;

    public function execute(): ResultInterface
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        try {
            /** @var Collection $collection */
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $count = $this->operate($collection);
            $this->messageManager->addSuccessMessage(
                __('A total of %1 record(s) were updated.', $count)
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Mass action failed: %1', $e->getMessage()));
        }
        return $resultRedirect->setPath('*/*/index');
    }

    /**
     * @return int[]
     */
    protected function ids(Collection $collection): array
    {
        return array_map('intval', $collection->getAllIds());
    }
}
