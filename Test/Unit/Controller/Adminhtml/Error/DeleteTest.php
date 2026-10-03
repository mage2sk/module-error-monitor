<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Controller\Adminhtml\Error;

use Panth\ErrorMonitor\Controller\Adminhtml\Error\Delete;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ErrorGroupFactory;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use PHPUnit\Framework\TestCase;

class DeleteTest extends TestCase
{
    use BackendContextTrait;

    private array $loaded = [];

    private array $deleted = [];

    private ?\Throwable $deleteFailure = null;

    private function controller(?int $existingId): Delete
    {
        $group = $this->createStub(ErrorGroup::class);
        $group->method('getId')->willReturn($existingId);
        $factory = $this->createStub(ErrorGroupFactory::class);
        $factory->method('create')->willReturn($group);

        $resource = $this->createStub(ErrorGroupResource::class);
        $resource->method('load')->willReturnCallback(function ($object, $id) use ($resource) {
            $this->loaded[] = $id;
            return $resource;
        });
        $resource->method('delete')->willReturnCallback(function ($object) use ($resource) {
            if ($this->deleteFailure !== null) {
                throw $this->deleteFailure;
            }
            $this->deleted[] = $object;
            return $resource;
        });

        return new Delete($this->backendContext(), $factory, $resource);
    }

    public function testMissingIdRedirectsWithError(): void
    {
        $result = $this->controller(null)->execute();
        $this->assertSame($this->redirect, $result);
        $this->assertSame('*/*/index', $this->redirectPath);
        $this->assertSame([['error', 'No error specified.']], $this->messages);
        $this->assertSame([], $this->loaded);
    }

    public function testUnknownGroupRedirectsWithError(): void
    {
        $this->params = ['group_id' => '12'];
        $this->controller(null)->execute();
        $this->assertSame([12], $this->loaded);
        $this->assertSame([['error', 'This error no longer exists.']], $this->messages);
        $this->assertSame([], $this->deleted);
        $this->assertSame('*/*/index', $this->redirectPath);
    }

    public function testExistingGroupIsDeleted(): void
    {
        $this->params = ['group_id' => '5'];
        $this->controller(5)->execute();
        $this->assertCount(1, $this->deleted);
        $this->assertSame([['success', 'Error deleted.']], $this->messages);
        $this->assertSame('*/*/index', $this->redirectPath);
    }

    public function testDeleteFailureIsReported(): void
    {
        $this->params = ['group_id' => '5'];
        $this->deleteFailure = new \RuntimeException('fk violation');
        $this->controller(5)->execute();
        $this->assertSame([['error', 'Could not delete the error: fk violation']], $this->messages);
        $this->assertSame('*/*/index', $this->redirectPath);
    }

    public function testAclResource(): void
    {
        $this->assertSame('Panth_ErrorMonitor::manage', Delete::ADMIN_RESOURCE);
        $this->assertAclResource($this->controller(null), 'Panth_ErrorMonitor::manage');
    }
}
