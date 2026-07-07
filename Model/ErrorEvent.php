<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;

class ErrorEvent extends AbstractModel
{
    protected $_eventPrefix = 'panth_error_event';

    protected function _construct(): void
    {
        $this->_init(ErrorEventResource::class);
    }
}
