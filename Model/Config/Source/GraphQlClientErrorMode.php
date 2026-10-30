<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\ErrorMonitor\Service\GraphQlClientErrorFilter;

class GraphQlClientErrorMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => GraphQlClientErrorFilter::MODE_SKIP, 'label' => __('Do not record (recommended)')],
            ['value' => GraphQlClientErrorFilter::MODE_NOTICE, 'label' => __('Record as notice, never email')],
            ['value' => GraphQlClientErrorFilter::MODE_RECORD, 'label' => __('Record as error (legacy behaviour)')],
        ];
    }
}
