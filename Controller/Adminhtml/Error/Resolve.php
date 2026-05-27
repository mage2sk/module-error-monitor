<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Controller\Adminhtml\Error;

use Magento\Framework\Phrase;
use Panth\ErrorMonitor\Model\ErrorGroup;

class Resolve extends AbstractStatusAction
{
    protected function getTargetStatus(): int
    {
        return ErrorGroup::STATUS_RESOLVED;
    }

    protected function getSuccessMessage(): Phrase
    {
        return __('Error marked as resolved.');
    }
}
