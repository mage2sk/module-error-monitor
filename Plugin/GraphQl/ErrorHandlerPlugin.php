<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Plugin\GraphQl;

use Magento\Framework\GraphQl\Query\ErrorHandlerInterface;
use Panth\ErrorMonitor\Service\GraphQlClientErrorFilter;

class ErrorHandlerPlugin
{
    public function __construct(
        private readonly GraphQlClientErrorFilter $filter
    ) {
    }

    public function beforeHandle(ErrorHandlerInterface $subject, array $errors, callable $formatter): ?array
    {
        try {
            $this->filter->register($errors);
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }
}
