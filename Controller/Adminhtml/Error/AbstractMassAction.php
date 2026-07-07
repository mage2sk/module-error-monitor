<?php
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

    abstract protected function operate(Collection $collection): int;

    public function execute(): ResultInterface
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        try {
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

    protected function ids(Collection $collection): array
    {
        return array_map('intval', $collection->getAllIds());
    }
}
