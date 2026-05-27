<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Controller\Adminhtml\Error;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ErrorGroupFactory;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_ErrorMonitor::view';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly ErrorGroupFactory $groupFactory,
        private readonly ErrorGroupResource $groupResource
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $id = (int)$this->getRequest()->getParam('group_id');
        /** @var ErrorGroup $group */
        $group = $this->groupFactory->create();
        if ($id) {
            $this->groupResource->load($group, $id);
        }
        if (!$group->getId()) {
            $this->messageManager->addErrorMessage(__('This error no longer exists.'));
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('*/*/index');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Panth_ErrorMonitor::error_log');
        $resultPage->getConfig()->getTitle()->prepend(__('Error #%1', $id));
        return $resultPage;
    }
}
