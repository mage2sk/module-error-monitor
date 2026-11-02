<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ErrorGroup extends AbstractDb
{
    public const TABLE_NAME = 'panth_error_group';

    public const HOURLY_TABLE = 'panth_error_group_hourly';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, 'group_id');
    }

    public function getHourlyTable(): string
    {
        $table = $this->getTable(self::HOURLY_TABLE);
        return $table !== '' ? $table : self::HOURLY_TABLE;
    }
}
