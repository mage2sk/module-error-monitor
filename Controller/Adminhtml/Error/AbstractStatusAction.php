<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Controller\Adminhtml\Error;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Panth\ErrorMonitor\Model\ErrorGroupFactory;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;

abstract class AbstractStatusAction extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ErrorMonitor::manage';

    public function __construct(
        Context $context,
        protected readonly ErrorGroupFactory $groupFactory,
        protected readonly ErrorGroupResource $groupResource
    ) {
        parent::__construct($context);
    }

    abstract protected function getTargetStatus(): int;

    abstract protected function getSuccessMessage(): \Magento\Framework\Phrase;

    public function execute(): ResultInterface
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $id = (int)$this->getRequest()->getParam('group_id');
        if (!$id) {
            $this->messageManager->addErrorMessage(__('No error specified.'));
            return $resultRedirect->setPath('*/*/index');
        }
        try {
            $group = $this->groupFactory->create();
            $this->groupResource->load($group, $id);
            if (!$group->getId()) {
                $this->messageManager->addErrorMessage(__('This error no longer exists.'));
                return $resultRedirect->setPath('*/*/index');
            }
            $group->setData('status', $this->getTargetStatus());
            $this->groupResource->save($group);
            $this->messageManager->addSuccessMessage($this->getSuccessMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not update the error: %1', $e->getMessage()));
        }
        return $resultRedirect->setPath('*/*/index');
    }
}
