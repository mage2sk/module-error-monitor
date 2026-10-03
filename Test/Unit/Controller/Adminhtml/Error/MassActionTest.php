<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Controller\Adminhtml\Error;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Ui\Component\MassAction\Filter;
use Panth\ErrorMonitor\Controller\Adminhtml\Error\AbstractMassAction;
use Panth\ErrorMonitor\Controller\Adminhtml\Error\MassDelete;
use Panth\ErrorMonitor\Controller\Adminhtml\Error\MassIgnore;
use Panth\ErrorMonitor\Controller\Adminhtml\Error\MassResolve;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\Collection;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use PHPUnit\Framework\TestCase;

class MassActionTest extends TestCase
{
    use BackendContextTrait;

    private array $dbCalls = [];

    private ?\Throwable $filterFailure = null;

    private function controller(string $class, array $ids): AbstractMassAction
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getAllIds')->willReturn($ids);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturnCallback(function ($given) use ($collection) {
            if ($this->filterFailure !== null) {
                throw $this->filterFailure;
            }
            $this->assertSame($collection, $given);
            return $given;
        });

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('delete')->willReturnCallback(function ($table, $where): int {
            $this->dbCalls[] = ['delete', $table, $where];
            return count($where['group_id IN (?)']);
        });
        $conn->method('update')->willReturnCallback(function ($table, $bind, $where): int {
            $this->dbCalls[] = ['update', $table, $bind, $where];
            return count($where['group_id IN (?)']);
        });
        $resource = $this->createStub(ErrorGroupResource::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getMainTable')->willReturn('panth_error_group');

        return new $class($this->backendContext(), $filter, $factory, $resource);
    }

    public function testMassDeleteRemovesSelectedRows(): void
    {
        $result = $this->controller(MassDelete::class, ['4', '7'])->execute();

        $this->assertSame($this->redirect, $result);
        $this->assertSame([['delete', 'panth_error_group', ['group_id IN (?)' => [4, 7]]]], $this->dbCalls);
        $this->assertSame([['success', 'A total of 2 record(s) were updated.']], $this->messages);
        $this->assertSame('*/*/index', $this->redirectPath);
    }

    public function testMassResolveUpdatesStatus(): void
    {
        $this->controller(MassResolve::class, ['1', '2', '3'])->execute();
        $this->assertSame([[
            'update',
            'panth_error_group',
            ['status' => ErrorGroup::STATUS_RESOLVED],
            ['group_id IN (?)' => [1, 2, 3]],
        ]], $this->dbCalls);
        $this->assertSame([['success', 'A total of 3 record(s) were updated.']], $this->messages);
    }

    public function testMassIgnoreUpdatesStatus(): void
    {
        $this->controller(MassIgnore::class, ['9'])->execute();
        $this->assertSame([[
            'update',
            'panth_error_group',
            ['status' => ErrorGroup::STATUS_IGNORED],
            ['group_id IN (?)' => [9]],
        ]], $this->dbCalls);
    }

    public function testEmptySelectionTouchesNothing(): void
    {
        foreach ([MassDelete::class, MassResolve::class, MassIgnore::class] as $class) {
            $this->messages = [];
            $this->controller($class, [])->execute();
            $this->assertSame([['success', 'A total of 0 record(s) were updated.']], $this->messages, $class);
        }
        $this->assertSame([], $this->dbCalls);
    }

    public function testFailureIsReported(): void
    {
        $this->filterFailure = new \RuntimeException('bad namespace');
        $this->controller(MassDelete::class, ['1'])->execute();
        $this->assertSame([['error', 'Mass action failed: bad namespace']], $this->messages);
        $this->assertSame('*/*/index', $this->redirectPath);
        $this->assertSame([], $this->dbCalls);
    }

    public function testAclResource(): void
    {
        $this->assertSame('Panth_ErrorMonitor::manage', AbstractMassAction::ADMIN_RESOURCE);
        $this->assertAclResource($this->controller(MassResolve::class, []), 'Panth_ErrorMonitor::manage');
    }
}
