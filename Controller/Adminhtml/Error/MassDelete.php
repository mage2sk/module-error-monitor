<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\ErrorMonitor\Controller\Adminhtml\Error;

use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\Collection;

class MassDelete extends AbstractMassAction
{
    protected function operate(Collection $collection): int
    {
        $ids = $this->ids($collection);
        if ($ids === []) {
            return 0;
        }
        // Events cascade via the FK constraint.
        return (int)$this->groupResource->getConnection()->delete(
            $this->groupResource->getMainTable(),
            ['group_id IN (?)' => $ids]
        );
    }
}
