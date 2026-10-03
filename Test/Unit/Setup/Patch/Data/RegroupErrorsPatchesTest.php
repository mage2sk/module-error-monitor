<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Setup\Patch\Data;

use Panth\ErrorMonitor\Service\Regrouper;
use Panth\ErrorMonitor\Setup\Patch\Data\RegroupErrorsV2;
use Panth\ErrorMonitor\Setup\Patch\Data\RegroupErrorsV3;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegroupErrorsPatchesTest extends TestCase
{
    private array $logs = [];

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function ($message): void {
            $this->logs[] = ['info', $message];
        });
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->logs[] = ['warning', $message];
        });
        return $logger;
    }

    public static function patchProvider(): array
    {
        return [
            'v2' => [RegroupErrorsV2::class, 'regroup'],
            'v3' => [RegroupErrorsV3::class, 'regroup v3'],
        ];
    }

    #[DataProvider('patchProvider')]
    public function testSuccessfulRegroupIsLogged(string $class, string $label): void
    {
        $regrouper = $this->createMock(Regrouper::class);
        $regrouper->expects($this->once())->method('regroupAll')->willReturn(['scanned' => 2, 'merged' => 1]);
        $patch = new $class($regrouper, $this->logger());

        $this->assertSame($patch, $patch->apply());
        $this->assertSame(
            [['info', '[PanthErrorMonitor] ' . $label . ' completed: {"scanned":2,"merged":1}']],
            $this->logs
        );
        $this->assertSame([], $patch->getAliases());
    }

    #[DataProvider('patchProvider')]
    public function testFailureIsLoggedNotThrown(string $class, string $label): void
    {
        $regrouper = $this->createStub(Regrouper::class);
        $regrouper->method('regroupAll')->willThrowException(new \RuntimeException('table missing'));
        $patch = new $class($regrouper, $this->logger());

        $this->assertSame($patch, $patch->apply());
        $this->assertSame([['warning', '[PanthErrorMonitor] ' . $label . ' failed: table missing']], $this->logs);
    }

    public function testDependencies(): void
    {
        $this->assertSame([], RegroupErrorsV2::getDependencies());
        $this->assertSame([RegroupErrorsV2::class], RegroupErrorsV3::getDependencies());
    }
}
