<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Controller\Adminhtml\Error;

use Magento\Framework\Phrase;
use Panth\ErrorMonitor\Model\ErrorGroup;

class Ignore extends AbstractStatusAction
{
    protected function getTargetStatus(): int
    {
        return ErrorGroup::STATUS_IGNORED;
    }

    protected function getSuccessMessage(): Phrase
    {
        return __('Error ignored. Future occurrences are still counted but won\'t be emailed.');
    }
}
