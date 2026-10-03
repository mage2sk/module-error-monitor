<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Controller\Adminhtml\Error;

use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\Collection;

class MassIgnore extends AbstractMassAction
{
    protected function operate(Collection $collection): int
    {
        $ids = $this->ids($collection);
        if ($ids === []) {
            return 0;
        }
        return (int)$this->groupResource->getConnection()->update(
            $this->groupResource->getMainTable(),
            ['status' => ErrorGroup::STATUS_IGNORED],
            ['group_id IN (?)' => $ids]
        );
    }
}
