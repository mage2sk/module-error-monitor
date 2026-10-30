<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Controller\Adminhtml\Error;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Message\ManagerInterface;

trait BackendContextTrait
{
    private array $params = [];

    private array $messages = [];

    private ?string $redirectPath = null;

    private array $aclChecks = [];

    private ?Redirect $redirect = null;

    private function backendContext(): Context
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn ($key, $default = null) => $this->params[$key] ?? $default
        );

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->messages[] = ['error', (string)$message];
            return $messages;
        });
        $messages->method('addSuccessMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->messages[] = ['success', (string)$message];
            return $messages;
        });

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path) use ($redirect) {
            $this->redirectPath = $path;
            return $redirect;
        });
        $this->redirect = $redirect;
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(function ($resource): bool {
            $this->aclChecks[] = $resource;
            return true;
        });

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getAuthorization')->willReturn($authorization);
        return $context;
    }

    private function assertAclResource(object $controller, string $resource): void
    {
        $method = new \ReflectionMethod($controller, '_isAllowed');
        $this->assertTrue($method->invoke($controller));
        $this->assertSame([$resource], $this->aclChecks);
    }
}
