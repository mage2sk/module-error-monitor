<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;

class ErrorGroup extends AbstractModel
{
    public const STATUS_NEW = 0;
    public const STATUS_RESOLVED = 1;
    public const STATUS_IGNORED = 2;

    public const SOURCE_PHP = 'php';
    public const SOURCE_JS = 'js';

    protected $_eventPrefix = 'panth_error_group';

    protected function _construct(): void
    {
        $this->_init(ErrorGroupResource::class);
    }
}
