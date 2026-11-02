<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Panth\ErrorMonitor\Service\BotDetector;
use Panth\ErrorMonitor\Service\IpRange;

class IpNetworkList extends Value
{
    private const MAX_REPORTED = 5;

    public function beforeSave()
    {
        $lines = BotDetector::splitLines((string)$this->getValue());
        $invalid = IpRange::invalidLines($lines);
        if ($invalid !== []) {
            throw new LocalizedException(__(
                '"%1" contains lines that are not an IPv4/IPv6 address or CIDR range: %2',
                (string)($this->getData('field_config/label') ?: $this->getPath()),
                implode(', ', array_slice($invalid, 0, self::MAX_REPORTED))
                    . (count($invalid) > self::MAX_REPORTED ? ', ...' : '')
            ));
        }
        $this->setValue(implode("\n", $lines));
        return parent::beforeSave();
    }
}
