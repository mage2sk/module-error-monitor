<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Panth\ErrorMonitor\Service\BotDetector;
use Panth\ErrorMonitor\Service\IpRange;

/**
 * IP / CIDR list field. The value is always saved; lines that are not a valid address or range are listed in an
 * admin warning and skipped when the list is used. Text after "#" is a comment.
 */
class IpNetworkList extends Value
{
    private const MAX_REPORTED = 5;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ManagerInterface $messageManager
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly ManagerInterface $messageManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Normalises the lines and warns about invalid ones without rejecting the save.
     *
     * @return $this
     */
    public function beforeSave()
    {
        $lines = BotDetector::splitLines((string)$this->getValue());
        $this->setValue(implode("\n", $lines));
        try {
            $invalid = IpRange::invalidLines($lines);
            if ($invalid !== []) {
                $this->messageManager->addWarningMessage(__(
                    '"%1": %2 line(s) are not an IPv4/IPv6 address or CIDR range and will be skipped: %3',
                    (string)($this->getData('field_config/label') ?: $this->getPath()),
                    count($invalid),
                    implode(', ', array_slice($invalid, 0, self::MAX_REPORTED))
                        . (count($invalid) > self::MAX_REPORTED ? ', ...' : '')
                ));
            }
        } catch (\Throwable $e) {
            $this->_logger->warning('[PanthErrorMonitor] IP list validation failed: ' . $e->getMessage());
        }
        return parent::beforeSave();
    }
}
