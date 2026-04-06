<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Controller\Api;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Controller\Api\ImageGenerationApiController;
use ArnaudMoncondhuy\SynapseCore\Service\ImageGenerationService;
use PHPUnit\Framework\TestCase;

class ImageGenerationApiControllerTest extends TestCase
{
    public function testControllerCanBeInstantiated(): void
    {
        $imageService = $this->createStub(ImageGenerationService::class);
        $permissionChecker = $this->createStub(PermissionCheckerInterface::class);

        $controller = new ImageGenerationApiController($imageService, $permissionChecker);

        $this->assertInstanceOf(ImageGenerationApiController::class, $controller);
    }

    public function testGenerateMethodExists(): void
    {
        $this->assertTrue(method_exists(ImageGenerationApiController::class, 'generate'));
    }

    public function testProvidersMethodExists(): void
    {
        $this->assertTrue(method_exists(ImageGenerationApiController::class, 'providers'));
    }
}
