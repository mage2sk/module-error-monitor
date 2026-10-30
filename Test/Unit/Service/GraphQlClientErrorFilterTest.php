<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use GraphQL\Error\Error as GraphQlError;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\Phrase;
use Panth\ErrorMonitor\Service\GraphQlClientErrorFilter;
use PHPUnit\Framework\TestCase;

class GraphQlClientErrorFilterTest extends TestCase
{
    private ?string $mode = null;

    protected function setUp(): void
    {
        GraphQlClientErrorFilter::reset();
    }

    protected function tearDown(): void
    {
        GraphQlClientErrorFilter::reset();
    }

    private function filter(): GraphQlClientErrorFilter
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn () => $this->mode);
        return new GraphQlClientErrorFilter($scope);
    }

    public function testClientSafeExceptionInContext(): void
    {
        $error = new GraphQlError('Cannot query field "stock_status" on type "ProductInterface".');
        $this->assertTrue($this->filter()->isClientSafe('ignored', ['exception' => $error]));
    }

    public function testServerFaultWrappedInGraphQlErrorIsNotClientSafe(): void
    {
        $error = new GraphQlError('Internal server error', null, null, [], null, new \RuntimeException('db down'));
        $this->assertFalse($this->filter()->isClientSafe('ignored', ['exception' => $error]));
        $this->assertFalse($this->filter()->isClientSafe('x', ['exception' => new \RuntimeException('boom')]));
    }

    public function testMagentoInputExceptionIsClientSafe(): void
    {
        $input = new GraphQlInputException(new Phrase('The region_id does not match the selected country or region'));
        $this->assertTrue($this->filter()->isClientSafeThrowable($input));
    }

    public function testStringifiedBareGraphQlErrorIsClientSafe(): void
    {
        $message = "GraphQL\\Error\\Error: Cannot query field \"stock_status\" on type \"ProductInterface\". in"
            . " /var/www/vendor/webonyx/graphql-php/src/Validator/Rules/FieldsOnCorrectType.php:42\nStack trace:\n#0 {main}";
        $this->assertTrue($this->filter()->isClientSafe($message));
    }

    public function testStringifiedChainNeedsRegistration(): void
    {
        $previous = new GraphQlInputException(new Phrase('The specified region is not a part of the selected country.'));
        $error = new GraphQlError($previous->getMessage(), null, null, [], null, $previous);
        $message = (string)$error;

        $filter = $this->filter();
        $this->assertFalse($filter->isClientSafe($message));
        $filter->register([$error]);
        $this->assertTrue($filter->isClientSafe($message));
        $this->assertFalse($filter->isClientSafe('PDOException: SQLSTATE[HY000] gone away'));
    }

    public function testModeDefaultsToSkip(): void
    {
        $this->assertSame(GraphQlClientErrorFilter::MODE_SKIP, $this->filter()->mode());
        $this->mode = 'notice';
        $this->assertSame(GraphQlClientErrorFilter::MODE_NOTICE, $this->filter()->mode());
        $this->mode = 'bogus';
        $this->assertSame(GraphQlClientErrorFilter::MODE_SKIP, $this->filter()->mode());
    }
}
