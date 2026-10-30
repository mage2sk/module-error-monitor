<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\FlagManager;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use PHPUnit\Framework\TestCase;

class DeploymentGuardTest extends TestCase
{
    private string $root;

    private array $flags = [];

    private array $deleted = [];

    private int $flagReads = 0;

    private bool $maintenance = false;

    private ?\Throwable $maintenanceException = null;

    private bool $rootThrows = false;

    private $windowMinutes = '5';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/panth_em_guard_' . uniqid('', true);
        mkdir($this->root . '/pub/static', 0777, true);
        mkdir($this->root . '/generated/code', 0777, true);
        mkdir($this->root . '/generated/metadata', 0777, true);
        file_put_contents($this->root . '/pub/static/deployed_version.txt', '1');
        $old = time() - 7200;
        foreach ($this->watched() as $path) {
            touch($path, $old);
        }
    }

    protected function tearDown(): void
    {
        $file = $this->root . '/pub/static/deployed_version.txt';
        if (is_file($file)) {
            unlink($file);
        }
        foreach (['/generated/code', '/generated/metadata', '/generated', '/pub/static', '/pub', ''] as $dir) {
            if (is_dir($this->root . $dir)) {
                rmdir($this->root . $dir);
            }
        }
    }

    private function watched(): array
    {
        return [
            $this->root . '/pub/static/deployed_version.txt',
            $this->root . '/generated/code',
            $this->root . '/generated/metadata',
        ];
    }

    private function guard(): DeploymentGuard
    {
        $maintenance = $this->createStub(MaintenanceMode::class);
        $maintenance->method('isOn')->willReturnCallback(function (): bool {
            if ($this->maintenanceException !== null) {
                throw $this->maintenanceException;
            }
            return $this->maintenance;
        });

        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(function (string $code) {
            $this->flagReads++;
            return $this->flags[$code] ?? null;
        });
        $flagManager->method('saveFlag')->willReturnCallback(function (string $code, $value): bool {
            $this->flags[$code] = $value;
            return true;
        });
        $flagManager->method('deleteFlag')->willReturnCallback(function (string $code): bool {
            $this->deleted[] = $code;
            unset($this->flags[$code]);
            return true;
        });

        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getRoot')->willReturnCallback(function (): string {
            if ($this->rootThrows) {
                throw new \RuntimeException('no root');
            }
            return $this->root;
        });

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn () => $this->windowMinutes);

        return new DeploymentGuard($maintenance, $flagManager, $directoryList, $scope);
    }

    private function baseline(int $mtime): void
    {
        $data = [];
        foreach ($this->watched() as $path) {
            $data[$path] = $mtime;
        }
        $this->flags[DeploymentGuard::DEPLOY_MTIMES_FLAG] = json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    public function testMaintenanceModeSuspends(): void
    {
        $this->maintenance = true;
        $this->assertTrue($this->guard()->isCaptureSuspended());
    }

    public function testActivePauseSuspends(): void
    {
        $this->flags[DeploymentGuard::PAUSE_FLAG] = time() + 600;
        $this->assertTrue($this->guard()->isCaptureSuspended());
        $this->assertSame([], $this->deleted);
    }

    public function testExpiredPauseIsDeleted(): void
    {
        $this->windowMinutes = '0';
        $this->flags[DeploymentGuard::PAUSE_FLAG] = time() - 10;
        $this->assertFalse($this->guard()->isCaptureSuspended());
        $this->assertSame([DeploymentGuard::PAUSE_FLAG], $this->deleted);
    }

    public function testZeroWindowDisablesAutoDetection(): void
    {
        $this->windowMinutes = '0';
        $guard = $this->guard();
        $this->assertFalse($guard->isCaptureSuspended());
        $status = $guard->status();
        $this->assertSame('disabled (window = 0)', $status['auto_pause_reason']);
        $this->assertSame(0, $status['window_minutes']);
        $this->assertArrayNotHasKey(DeploymentGuard::DEPLOY_MTIMES_FLAG, $this->flags);
    }

    public function testActiveAutoPauseSuspends(): void
    {
        $this->flags[DeploymentGuard::AUTO_PAUSE_UNTIL_FLAG] = time() + 120;
        $guard = $this->guard();
        $this->assertTrue($guard->isCaptureSuspended());
        $this->assertStringStartsWith('auto-pause active until ', $guard->status()['auto_pause_reason']);
    }

    public function testExpiredAutoPauseIsDeletedAndBaselineEstablished(): void
    {
        $this->flags[DeploymentGuard::AUTO_PAUSE_UNTIL_FLAG] = time() - 5;
        $guard = $this->guard();
        $this->assertFalse($guard->isCaptureSuspended());
        $this->assertContains(DeploymentGuard::AUTO_PAUSE_UNTIL_FLAG, $this->deleted);

        $saved = json_decode((string)$this->flags[DeploymentGuard::DEPLOY_MTIMES_FLAG], true);
        $this->assertSame($this->watched(), array_keys($saved));
        $this->assertSame('baseline established (first observation)', $guard->status()['auto_pause_reason']);
    }

    public function testNoWatchedPathsMeansNotSuspended(): void
    {
        $this->root .= '/missing';
        $guard = $this->guard();
        $this->assertFalse($guard->isCaptureSuspended());
        $this->assertSame('no watched paths exist on disk', $guard->status()['auto_pause_reason']);
        $this->root = substr($this->root, 0, -strlen('/missing'));
    }

    public function testUnresolvableRootMeansNotSuspended(): void
    {
        $this->rootThrows = true;
        $guard = $this->guard();
        $this->assertFalse($guard->isCaptureSuspended());
        $this->assertSame([], $guard->status()['watched_mtimes']);
    }

    public function testUnchangedMtimesDoNotSuspend(): void
    {
        $this->baseline(time() - 7200);
        $guard = $this->guard();
        $this->assertFalse($guard->isCaptureSuspended());
        $this->assertSame('no deploy-marker mtime change since last check', $guard->status()['auto_pause_reason']);
    }

    public function testRecentDeployStartsAutoPause(): void
    {
        $this->baseline(time() - 7200);
        $recent = time() - 30;
        touch($this->root . '/pub/static/deployed_version.txt', $recent);

        $guard = $this->guard();
        $this->assertTrue($guard->isCaptureSuspended());
        $this->assertSame($recent + 300, $this->flags[DeploymentGuard::AUTO_PAUSE_UNTIL_FLAG]);
        $saved = json_decode((string)$this->flags[DeploymentGuard::DEPLOY_MTIMES_FLAG], true);
        $this->assertSame($recent, $saved[$this->root . '/pub/static/deployed_version.txt']);

        $status = $guard->status();
        $this->assertStringStartsWith('deploy detected (deployed_version.txt mtime ', $status['auto_pause_reason']);
        $this->assertSame($recent + 300, $status['auto_pause_until']);
        $this->assertTrue($status['suspended']);
    }

    public function testOldChangeOutsideWindowDoesNotSuspend(): void
    {
        $this->baseline(time() - 9000);
        $guard = $this->guard();
        $this->assertFalse($guard->isCaptureSuspended());
        $this->assertSame('change observed but window already elapsed', $guard->status()['auto_pause_reason']);
        $this->assertArrayNotHasKey(DeploymentGuard::AUTO_PAUSE_UNTIL_FLAG, $this->flags);
    }

    public function testCorruptBaselineIsTreatedAsFirstObservation(): void
    {
        $this->flags[DeploymentGuard::DEPLOY_MTIMES_FLAG] = 'not json';
        $guard = $this->guard();
        $this->assertFalse($guard->isCaptureSuspended());
        $this->assertSame('baseline established (first observation)', $guard->status()['auto_pause_reason']);
    }

    public function testBaselineEntriesWithInvalidValuesAreDropped(): void
    {
        $this->flags[DeploymentGuard::DEPLOY_MTIMES_FLAG] = json_encode([
            '/a' => 100,
            '/b' => '200',
            '/c' => 'abc',
            '/d' => 1.5,
        ]);
        $status = $this->guard()->status();
        $this->assertSame(['/a' => 100, '/b' => 200], $status['last_seen_mtimes']);
    }

    public function testWindowIsClampedToMaximum(): void
    {
        $this->windowMinutes = '500';
        $this->assertSame(120, $this->guard()->status()['window_minutes']);
    }

    public function testAutoDetectionResultIsMemoised(): void
    {
        $this->baseline(time() - 7200);
        $guard = $this->guard();
        $guard->isCaptureSuspended();
        $reads = $this->flagReads;
        $guard->isCaptureSuspended();
        $this->assertSame(1, $this->flagReads - $reads, 'Only the pause flag is re-read');
    }

    public function testFailuresFailOpen(): void
    {
        $this->maintenanceException = new \RuntimeException('broken');
        $guard = $this->guard();
        $this->assertFalse($guard->isCaptureSuspended());
        $status = $guard->status();
        $this->assertFalse($status['maintenance']);
    }

    public function testStatusReportsPauseAndMaintenance(): void
    {
        $this->maintenance = true;
        $expiry = time() + 900;
        $this->flags[DeploymentGuard::PAUSE_FLAG] = $expiry;
        $this->baseline(time() - 7200);

        $status = $this->guard()->status();
        $this->assertTrue($status['maintenance']);
        $this->assertSame($expiry, $status['paused_until']);
        $this->assertNull($status['auto_pause_until']);
        $this->assertTrue($status['suspended']);
        $this->assertSame(5, $status['window_minutes']);
        $this->assertCount(3, $status['watched_mtimes']);
    }

    public function testStatusIgnoresExpiredPause(): void
    {
        $this->windowMinutes = '0';
        $this->flags[DeploymentGuard::PAUSE_FLAG] = time() - 60;
        $status = $this->guard()->status();
        $this->assertNull($status['paused_until']);
        $this->assertFalse($status['suspended']);
    }

    public function testPauseStoresExpiryWithMinimumOneMinute(): void
    {
        $guard = $this->guard();
        $expiry = $guard->pause(0);
        $this->assertEqualsWithDelta(time() + 60, $expiry, 2);
        $this->assertSame($expiry, $this->flags[DeploymentGuard::PAUSE_FLAG]);

        $expiry = $guard->pause(30);
        $this->assertEqualsWithDelta(time() + 1800, $expiry, 2);
    }

    public function testResumeAndResetDeleteFlags(): void
    {
        $guard = $this->guard();
        $guard->resume();
        $this->assertSame([DeploymentGuard::PAUSE_FLAG], $this->deleted);

        $this->deleted = [];
        $guard->resetAutoDetect();
        $this->assertSame(
            [DeploymentGuard::AUTO_PAUSE_UNTIL_FLAG, DeploymentGuard::DEPLOY_MTIMES_FLAG],
            $this->deleted
        );
    }

    public function testDefaultAutoPauseMinutes(): void
    {
        $this->assertSame(5, DeploymentGuard::defaultAutoPauseMinutes());
    }
}
