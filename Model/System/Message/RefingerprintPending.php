<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\System\Message;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Notification\MessageInterface;

class RefingerprintPending implements MessageInterface
{
    public const FLAG_CODE = 'panth_errormonitor_refingerprint_pending';

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    public function getIdentity()
    {
        return hash('sha256', self::FLAG_CODE);
    }

    public function isDisplayed()
    {
        try {
            return (bool)$this->flagManager->getFlagData(self::FLAG_CODE)
                && $this->authorization->isAllowed('Panth_ErrorMonitor::view');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getText()
    {
        return (string)__(
            'Error Monitor was upgraded with new grouping rules. Run "bin/magento panth:errormonitor:refingerprint" once to merge error groups that were split by the old rules and to move stored GraphQL client errors to notice. This message disappears after the command has run.'
        );
    }

    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }
}
