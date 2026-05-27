<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Controller\Adminhtml\Error;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Panth\ErrorMonitor\Model\ErrorGroupFactory;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;

class Delete extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ErrorMonitor::manage';

    public function __construct(
        Context $context,
        private readonly ErrorGroupFactory $groupFactory,
        private readonly ErrorGroupResource $groupResource
    ) {
        parent::__construct($context);
    }

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
            $this->groupResource->delete($group);
            $this->messageManager->addSuccessMessage(__('Error deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not delete the error: %1', $e->getMessage()));
        }
        return $resultRedirect->setPath('*/*/index');
    }
}
