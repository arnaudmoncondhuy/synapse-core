<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Accounting;

use ArnaudMoncondhuy\SynapseCore\Accounting\TokenAccountingService;
use ArnaudMoncondhuy\SynapseCore\Accounting\TokenCostEstimator;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ContextTruncationService;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use PHPUnit\Framework\TestCase;

class TokenCostEstimatorTest extends TestCase
{
    private $configProvider;
    private $modelRepo;
    private $truncationService;
    private $accountingService;
    private $estimator;

    protected function setUp(): void
    {
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);
        $this->modelRepo = $this->createMock(SynapseModelRepository::class);
        $this->truncationService = $this->createMock(ContextTruncationService::class);
        $this->accountingService = $this->createMock(TokenAccountingService::class);

        $this->estimator = new TokenCostEstimator(
            $this->configProvider,
            $this->modelRepo,
            $this->truncationService,
            $this->accountingService
        );
    }

    public function testEstimateCost(): void
    {
        $this->configProvider->method('getConfig')->willReturn(['model' => 'gpt-4']);
        $this->truncationService->method('estimateTokensForContents')->willReturn(100);
        $this->modelRepo->method('findAllPricingMap')->willReturn([
            'gpt-4' => ['input' => 30.0, 'output' => 60.0, 'currency' => 'USD'],
        ]);

        $this->accountingService->method('calculateCost')->willReturn(0.123);
        $this->accountingService->method('convertToReferenceCurrency')->willReturn(0.123);

        $result = $this->estimator->estimateCost([['role' => 'user', 'content' => 'hello']], null, 2000);

        $this->assertSame(100, $result['prompt_tokens']);
        $this->assertSame(2000, $result['estimated_output_tokens']);
        $this->assertSame(0.123, $result['cost_model_currency']);
        $this->assertSame('USD', $result['currency']);
    }
}
