<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Plugin\Cron;

use Magento\Cron\Model\Schedule;
use Panth\ErrorMonitor\Service\CliContext;

class ScheduleContextPlugin
{
    public function afterTryLockJob(Schedule $subject, $result)
    {
        if ($result) {
            CliContext::setCronJob((string)$subject->getJobCode());
        }
        return $result;
    }

    public function afterSave(Schedule $subject, $result)
    {
        $status = (string)$subject->getStatus();
        if ($status !== Schedule::STATUS_RUNNING && CliContext::getCronJob() === (string)$subject->getJobCode()) {
            CliContext::setCronJob(null);
        }
        return $result;
    }
}
