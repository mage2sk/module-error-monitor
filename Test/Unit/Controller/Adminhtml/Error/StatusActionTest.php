<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Controller\Adminhtml\Error;

use Panth\ErrorMonitor\Controller\Adminhtml\Error\AbstractStatusAction;
use Panth\ErrorMonitor\Controller\Adminhtml\Error\Ignore;
use Panth\ErrorMonitor\Controller\Adminhtml\Error\Resolve;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ErrorGroupFactory;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatusActionTest extends TestCase
{
    use BackendContextTrait;

    private array $saved = [];

    private array $statuses = [];

    private ?\Throwable $saveFailure = null;

    private function controller(string $class, ?int $existingId): AbstractStatusAction
    {
        $group = $this->createStub(ErrorGroup::class);
        $group->method('getId')->willReturn($existingId);
        $group->method('setData')->willReturnCallback(function ($key, $value = null) use ($group) {
            $this->statuses[] = [$key, $value];
            return $group;
        });
        $factory = $this->createStub(ErrorGroupFactory::class);
        $factory->method('create')->willReturn($group);

        $resource = $this->createStub(ErrorGroupResource::class);
        $resource->method('load')->willReturn($resource);
        $resource->method('save')->willReturnCallback(function ($object) use ($resource) {
            if ($this->saveFailure !== null) {
                throw $this->saveFailure;
            }
            $this->saved[] = $object;
            return $resource;
        });

        return new $class($this->backendContext(), $factory, $resource);
    }

    public static function actionProvider(): array
    {
        return [
            'resolve' => [Resolve::class, ErrorGroup::STATUS_RESOLVED, 'Error marked as resolved.'],
            'ignore' => [
                Ignore::class,
                ErrorGroup::STATUS_IGNORED,
                'Error ignored. Future occurrences are still counted but won\'t be emailed.',
            ],
        ];
    }

    public static function classProvider(): array
    {
        return ['resolve' => [Resolve::class], 'ignore' => [Ignore::class]];
    }

    #[DataProvider('actionProvider')]
    public function testStatusIsUpdated(string $class, int $status, string $message): void
    {
        $this->params = ['group_id' => '3'];
        $this->controller($class, 3)->execute();

        $this->assertSame([['status', $status]], $this->statuses);
        $this->assertCount(1, $this->saved);
        $this->assertSame([['success', $message]], $this->messages);
        $this->assertSame('*/*/index', $this->redirectPath);
    }

    #[DataProvider('classProvider')]
    public function testMissingIdIsRejected(string $class): void
    {
        $this->controller($class, null)->execute();
        $this->assertSame([['error', 'No error specified.']], $this->messages);
        $this->assertSame([], $this->saved);
    }

    #[DataProvider('classProvider')]
    public function testUnknownGroupIsRejected(string $class): void
    {
        $this->params = ['group_id' => '99'];
        $this->controller($class, null)->execute();
        $this->assertSame([['error', 'This error no longer exists.']], $this->messages);
        $this->assertSame([], $this->statuses);
    }

    #[DataProvider('classProvider')]
    public function testSaveFailureIsReported(string $class): void
    {
        $this->params = ['group_id' => '3'];
        $this->saveFailure = new \RuntimeException('read only');
        $this->controller($class, 3)->execute();
        $this->assertSame([['error', 'Could not update the error: read only']], $this->messages);
        $this->assertSame('*/*/index', $this->redirectPath);
    }

    #[DataProvider('classProvider')]
    public function testAclResource(string $class): void
    {
        $this->assertAclResource($this->controller($class, null), 'Panth_ErrorMonitor::manage');
    }
}
