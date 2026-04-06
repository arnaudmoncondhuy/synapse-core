<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Controller\Api;

use ArnaudMoncondhuy\SynapseCore\Accounting\TokenCostEstimator;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Controller\Api\EstimateCostApiController;
use ArnaudMoncondhuy\SynapseCore\Formatter\MessageFormatter;
use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use PHPUnit\Framework\TestCase;

class EstimateCostApiControllerTest extends TestCase
{
    public function testControllerCanBeInstantiated(): void
    {
        $estimator = $this->createStub(TokenCostEstimator::class);
        $permissionChecker = $this->createStub(PermissionCheckerInterface::class);

        $controller = new EstimateCostApiController($estimator, $permissionChecker);

        $this->assertInstanceOf(EstimateCostApiController::class, $controller);
    }

    public function testControllerCanBeInstantiatedWithAllDeps(): void
    {
        $estimator = $this->createStub(TokenCostEstimator::class);
        $permissionChecker = $this->createStub(PermissionCheckerInterface::class);
        $conversationManager = $this->createStub(ConversationManager::class);
        $messageFormatter = $this->createStub(MessageFormatter::class);

        $controller = new EstimateCostApiController(
            $estimator,
            $permissionChecker,
            $conversationManager,
            $messageFormatter
        );

        $this->assertInstanceOf(EstimateCostApiController::class, $controller);
    }

    public function testEstimateCostMethodExists(): void
    {
        $this->assertTrue(method_exists(EstimateCostApiController::class, 'estimateCost'));
    }
}
