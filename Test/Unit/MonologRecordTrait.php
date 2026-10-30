<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit;

use Monolog\Logger;

trait MonologRecordTrait
{
    private function makeRecord(
        string $level,
        string $message,
        array $context = [],
        string $channel = 'main',
        array $extra = []
    ) {
        $monologLevel = Logger::toMonologLevel($level);
        if (class_exists(\Monolog\LogRecord::class)) {
            return new \Monolog\LogRecord(
                new \DateTimeImmutable(),
                $channel,
                $monologLevel,
                $message,
                $context,
                $extra
            );
        }
        return [
            'message' => $message,
            'context' => $context,
            'level' => $monologLevel,
            'level_name' => Logger::getLevelName($monologLevel),
            'channel' => $channel,
            'datetime' => new \DateTimeImmutable(),
            'extra' => $extra,
        ];
    }
}
