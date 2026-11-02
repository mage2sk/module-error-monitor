<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\ErrorMonitor\Model\ErrorEvent;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'event_id';

    protected function _construct(): void
    {
        $this->_init(ErrorEvent::class, ErrorEventResource::class);
    }
}
