<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'group_id';

    protected function _construct(): void
    {
        $this->_init(ErrorGroup::class, ErrorGroupResource::class);
    }
}
