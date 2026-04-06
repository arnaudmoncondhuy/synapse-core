<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Controller\Api;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Controller\Api\MemoryApiController;
use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Memory\MemoryManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MemoryApiControllerTest extends TestCase
{
    public function testControllerCanBeInstantiated(): void
    {
        $memoryManager = $this->createStub(MemoryManager::class);
        $permissionChecker = $this->createStub(PermissionCheckerInterface::class);

        $controller = new MemoryApiController($memoryManager, $permissionChecker);

        $this->assertInstanceOf(MemoryApiController::class, $controller);
    }

    public function testControllerCanBeInstantiatedWithAllDeps(): void
    {
        $memoryManager = $this->createStub(MemoryManager::class);
        $permissionChecker = $this->createStub(PermissionCheckerInterface::class);
        $conversationManager = $this->createStub(ConversationManager::class);
        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $controller = new MemoryApiController(
            $memoryManager,
            $permissionChecker,
            $conversationManager,
            $csrfTokenManager,
            $translator
        );

        $this->assertInstanceOf(MemoryApiController::class, $controller);
    }

    public function testPublicEndpointsExist(): void
    {
        $methods = ['confirm', 'reject', 'list', 'createManual', 'update', 'delete'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(MemoryApiController::class, $method),
                sprintf('Method %s should exist on MemoryApiController', $method)
            );
        }
    }
}
