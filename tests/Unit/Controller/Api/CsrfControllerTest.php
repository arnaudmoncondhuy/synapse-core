<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Controller\Api;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Controller\Api\CsrfController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class CsrfControllerTest extends TestCase
{
    public function testControllerCanBeInstantiated(): void
    {
        $permissionChecker = $this->createStub(PermissionCheckerInterface::class);
        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);

        $controller = new CsrfController($permissionChecker, $csrfTokenManager);

        $this->assertInstanceOf(CsrfController::class, $controller);
    }

    public function testControllerCanBeInstantiatedWithoutCsrfManager(): void
    {
        $permissionChecker = $this->createStub(PermissionCheckerInterface::class);

        $controller = new CsrfController($permissionChecker);

        $this->assertInstanceOf(CsrfController::class, $controller);
    }

    public function testTokenMethodExists(): void
    {
        $this->assertTrue(method_exists(CsrfController::class, 'token'));
    }
}
