<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ErrorEvent extends AbstractDb
{
    public const TABLE_NAME = 'panth_error_event';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, 'event_id');
    }
}
