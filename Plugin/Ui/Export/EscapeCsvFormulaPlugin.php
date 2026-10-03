<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Plugin\Ui\Export;

use Magento\Framework\App\RequestInterface;
use Magento\Ui\Model\Export\MetadataProvider;

class EscapeCsvFormulaPlugin
{
    public const LISTING_NAMESPACE = 'panth_errormonitor_group_listing';

    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    public function afterGetRowData(MetadataProvider $subject, array $result): array
    {
        if ((string)$this->request->getParam('namespace') !== self::LISTING_NAMESPACE
            || strtolower((string)$this->request->getActionName()) !== 'gridtocsv'
        ) {
            return $result;
        }

        foreach ($result as $key => $value) {
            $result[$key] = $this->escape($value);
        }
        return $result;
    }

    public function escape(mixed $value): mixed
    {
        if (!is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }
        if (in_array($value[0], self::FORMULA_PREFIXES, true)) {
            return "'" . $value;
        }
        return $value;
    }
}
