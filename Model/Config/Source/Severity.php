<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Severity implements OptionSourceInterface
{
    public const RANKS = [
        'debug'     => 1,
        'info'      => 2,
        'notice'    => 3,
        'warning'   => 4,
        'error'     => 5,
        'critical'  => 6,
        'alert'     => 7,
        'emergency' => 8,
    ];

    public function toOptionArray(): array
    {
        return [
            ['value' => 'notice', 'label' => __('Notice')],
            ['value' => 'warning', 'label' => __('Warning')],
            ['value' => 'error', 'label' => __('Error')],
            ['value' => 'critical', 'label' => __('Critical')],
            ['value' => 'alert', 'label' => __('Alert')],
            ['value' => 'emergency', 'label' => __('Emergency')],
        ];
    }

    public static function rank(string $severity): int
    {
        return self::RANKS[strtolower($severity)] ?? 0;
    }
}
