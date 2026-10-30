<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\ErrorMonitor\Setup\Patch\Data\AddStaleCacheDefaults;
use Panth\ErrorMonitor\Setup\Patch\Data\MigrateEmailModeToDaily;
use Panth\ErrorMonitor\Setup\Patch\Data\MigrateIgnorePatternsToGeneral;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigMigrationPatchesTest extends TestCase
{
    private array $rows = [];

    private array $existing = [];

    private array $wheres = [];

    private array $saves = [];

    private array $logs = [];

    private bool $dbFails = false;

    private function resource(): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchAll')->willReturnCallback(fn () => $this->rows);
        $conn->method('fetchOne')->willReturnCallback(fn () => array_shift($this->existing));

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturnCallback(function () use ($conn) {
            if ($this->dbFails) {
                throw new \RuntimeException('no db');
            }
            return $conn;
        });
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function writer(): WriterInterface
    {
        $writer = $this->createStub(WriterInterface::class);
        $writer->method('save')->willReturnCallback(function ($path, $value, $scope, $scopeId): void {
            $this->saves[] = [$path, $value, $scope, $scopeId];
        });
        return $writer;
    }

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

    public function testStaleCacheDefaultsAppendMissingLines(): void
    {
        $this->rows = [
            ['scope' => 'default', 'scope_id' => '0', 'value' => "Custom noise\r\nloading CHUNK\n"],
            ['scope' => 'stores', 'scope_id' => '2', 'value' => '   '],
            ['scope' => 'websites', 'scope_id' => '1', 'value' => "Script error for\nChunkLoadError\nLoading chunk"],
        ];
        $patch = new AddStaleCacheDefaults($this->resource(), $this->writer(), $this->logger());

        $this->assertSame($patch, $patch->apply());
        $this->assertSame([[
            'panth_errormonitor/general/ignore_patterns',
            "Custom noise\r\nloading CHUNK\nScript error for\nChunkLoadError",
            'default',
            0,
        ]], $this->saves);
        $this->assertSame([['path = ?', 'panth_errormonitor/general/ignore_patterns']], $this->wheres);
        $this->assertSame(
            [['info', '[PanthErrorMonitor] appended stale-cache defaults to ignore_patterns on 1 scope(s)']],
            $this->logs
        );
        $this->assertSame([MigrateIgnorePatternsToGeneral::class], AddStaleCacheDefaults::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    public function testStaleCacheDefaultsNoChangesLogsNothing(): void
    {
        $patch = new AddStaleCacheDefaults($this->resource(), $this->writer(), $this->logger());
        $patch->apply();
        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->logs);
    }

    public function testStaleCacheDefaultsFailureIsLogged(): void
    {
        $this->dbFails = true;
        (new AddStaleCacheDefaults($this->resource(), $this->writer(), $this->logger()))->apply();
        $this->assertSame([['warning', '[PanthErrorMonitor] AddStaleCacheDefaults patch failed: no db']], $this->logs);
    }

    public function testEmailModeMigratedToDaily(): void
    {
        $this->rows = [
            ['scope' => 'default', 'scope_id' => '0'],
            ['scope' => 'websites', 'scope_id' => '3'],
        ];
        $patch = new MigrateEmailModeToDaily($this->resource(), $this->writer(), $this->logger());

        $this->assertSame($patch, $patch->apply());
        $this->assertSame([
            ['panth_errormonitor/email/mode', 'daily_summary', 'default', 0],
            ['panth_errormonitor/email/mode', 'daily_summary', 'websites', 3],
        ], $this->saves);
        $this->assertSame([
            ['path = ?', 'panth_errormonitor/email/mode'],
            ['value = ?', 'immediate_digest'],
        ], $this->wheres);
        $this->assertSame([['info', '[PanthErrorMonitor] migrated email mode to daily_summary on 2 scope(s)']], $this->logs);
        $this->assertSame([], MigrateEmailModeToDaily::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    public function testEmailModeNothingToMigrate(): void
    {
        (new MigrateEmailModeToDaily($this->resource(), $this->writer(), $this->logger()))->apply();
        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->logs);
    }

    public function testEmailModeFailureIsLogged(): void
    {
        $this->dbFails = true;
        (new MigrateEmailModeToDaily($this->resource(), $this->writer(), $this->logger()))->apply();
        $this->assertSame([['warning', '[PanthErrorMonitor] email-mode migration failed: no db']], $this->logs);
    }

    public function testIgnorePatternsCopiedOnlyWhereCanonicalIsEmpty(): void
    {
        $this->rows = [
            ['scope' => 'default', 'scope_id' => '0', 'value' => "  legacy one\nlegacy two  "],
            ['scope' => 'stores', 'scope_id' => '1', 'value' => ''],
            ['scope' => 'stores', 'scope_id' => '2', 'value' => 'keep'],
            ['scope' => 'websites', 'scope_id' => '1', 'value' => 'blank canonical'],
        ];
        $this->existing = [false, 'already set', '   '];
        $patch = new MigrateIgnorePatternsToGeneral($this->resource(), $this->writer(), $this->logger());

        $this->assertSame($patch, $patch->apply());
        $this->assertSame([
            ['panth_errormonitor/general/ignore_patterns', "legacy one\nlegacy two", 'default', 0],
            ['panth_errormonitor/general/ignore_patterns', 'blank canonical', 'websites', 1],
        ], $this->saves);
        $this->assertContains(['scope = ?', 'stores'], $this->wheres);
        $this->assertContains(['scope_id = ?', 2], $this->wheres);
        $this->assertSame([['info', '[PanthErrorMonitor] migrated ignore-patterns to general/ on 2 scope(s)']], $this->logs);
        $this->assertSame([], MigrateIgnorePatternsToGeneral::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    public function testIgnorePatternsNothingToCopy(): void
    {
        (new MigrateIgnorePatternsToGeneral($this->resource(), $this->writer(), $this->logger()))->apply();
        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->logs);
    }

    public function testIgnorePatternsFailureIsLogged(): void
    {
        $this->dbFails = true;
        (new MigrateIgnorePatternsToGeneral($this->resource(), $this->writer(), $this->logger()))->apply();
        $this->assertSame([['warning', '[PanthErrorMonitor] ignore-patterns migration failed: no db']], $this->logs);
    }
}
