<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Accounting;

use ArnaudMoncondhuy\SynapseCore\Accounting\TokenAccountingService;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseUsageRecordedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tests ciblés sur calculateCost(), convertToReferenceCurrency() et logUsage().
 * TokenAccountingServiceTest existant couvre déjà les cas de base.
 */
class TokenAccountingServiceCalculationsTest extends TestCase
{
    private SynapseModelRepository $modelRepo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->modelRepo = $this->createStub(SynapseModelRepository::class);
        $this->modelRepo->method('findAllPricingMap')->willReturn([]);
        $this->em = $this->createStub(EntityManagerInterface::class);
    }

    // -------------------------------------------------------------------------
    // calculateCost()
    // -------------------------------------------------------------------------

    public function testCalculateCostWithPromptAndCompletionTokens(): void
    {
        $service = $this->buildService();

        $cost = $service->calculateCost(
            ['prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000, 'thinking_tokens' => 0],
            ['input' => 1.0, 'output' => 2.0, 'currency' => 'USD'],
        );

        // 1M * 1.0/1M + 1M * 2.0/1M = 3.0
        $this->assertEqualsWithDelta(3.0, $cost, 0.000001);
    }

    public function testCalculateCostThinkingTokensCountAsOutputTokens(): void
    {
        $service = $this->buildService();

        $cost = $service->calculateCost(
            ['prompt_tokens' => 0, 'completion_tokens' => 0, 'thinking_tokens' => 1_000_000],
            ['input' => 1.0, 'output' => 3.0, 'currency' => 'USD'],
        );

        // thinking count in output : 1M * 3.0/1M = 3.0
        $this->assertEqualsWithDelta(3.0, $cost, 0.000001);
    }

    public function testCalculateCostZeroTokensReturnZero(): void
    {
        $service = $this->buildService();

        $cost = $service->calculateCost(
            ['prompt_tokens' => 0, 'completion_tokens' => 0, 'thinking_tokens' => 0],
            ['input' => 5.0, 'output' => 10.0, 'currency' => 'USD'],
        );

        $this->assertSame(0.0, $cost);
    }

    public function testCalculateCostRoundsToSixDecimals(): void
    {
        $service = $this->buildService();

        $cost = $service->calculateCost(
            ['prompt_tokens' => 1, 'completion_tokens' => 1, 'thinking_tokens' => 0],
            ['input' => 1.0, 'output' => 1.0, 'currency' => 'USD'],
        );

        // 1/1_000_000 + 1/1_000_000 = 0.000002
        $this->assertEqualsWithDelta(0.000002, $cost, 0.0000001);
    }

    // -------------------------------------------------------------------------
    // convertToReferenceCurrency()
    // -------------------------------------------------------------------------

    public function testConvertToReferenceCurrencyReturnsSameAmountIfSameCurrency(): void
    {
        $service = $this->buildService(referenceCurrency: 'EUR');

        $this->assertEqualsWithDelta(5.0, $service->convertToReferenceCurrency(5.0, 'EUR'), 0.000001);
    }

    public function testConvertToReferenceCurrencyAppliesRate(): void
    {
        $service = $this->buildService(
            referenceCurrency: 'EUR',
            currencyRates: ['USD' => 0.92],
        );

        // 10 USD * 0.92 = 9.2 EUR
        $this->assertEqualsWithDelta(9.2, $service->convertToReferenceCurrency(10.0, 'USD'), 0.000001);
    }

    public function testConvertToReferenceCurrencyReturnsSameAmountWhenNoRateDefined(): void
    {
        $service = $this->buildService(referenceCurrency: 'EUR', currencyRates: []);

        // Pas de taux USD → pas de conversion, on retourne tel quel
        $this->assertEqualsWithDelta(10.0, $service->convertToReferenceCurrency(10.0, 'USD'), 0.000001);
    }

    // -------------------------------------------------------------------------
    // logUsage() — dispatch event
    // -------------------------------------------------------------------------

    public function testLogUsageDispatchesUsageRecordedEvent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SynapseUsageRecordedEvent::class));

        $service = $this->buildService(dispatcher: $dispatcher);
        $service->logUsage('chat', 'ask', 'gemini-flash', [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ]);
    }

    public function testLogUsageDoesNotDispatchWhenNoDispatcher(): void
    {
        // Pas de dispatcher → pas d'exception
        $service = $this->buildService(dispatcher: null);
        $result = $service->logUsage('chat', 'ask', 'gemini-flash', [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ]);

        $this->assertSame(100, $result->getPromptTokens());
        $this->assertSame(50, $result->getCompletionTokens());
    }

    // -------------------------------------------------------------------------
    // logUsage() — fallback pricing via ModelCapabilityRegistry
    // -------------------------------------------------------------------------

    public function testLogUsageUsesPricingFromCapabilityRegistry(): void
    {
        $capabilities = new ModelCapabilities(
            model: 'gemini-flash',
            provider: 'gemini',
            pricingInput: 0.075,
            pricingOutput: 0.30,
        );

        $capabilityRegistry = $this->createStub(ModelCapabilityRegistry::class);
        $capabilityRegistry->method('getCapabilities')->willReturn($capabilities);

        $service = $this->buildService(capabilityRegistry: $capabilityRegistry);
        $result = $service->logUsage('chat', 'ask', 'gemini-flash', [
            'prompt_tokens' => 1_000_000,
            'completion_tokens' => 1_000_000,
        ]);

        // 1M * 0.075/1M + 1M * 0.30/1M = 0.375 USD
        $this->assertEqualsWithDelta(0.375, $result->getCostModelCurrency(), 0.0001);
    }

    public function testLogUsageDefaultsToZeroCostWhenNoPricing(): void
    {
        $service = $this->buildService();
        $result = $service->logUsage('chat', 'ask', 'modele-inconnu', [
            'prompt_tokens' => 1000,
            'completion_tokens' => 500,
        ]);

        $this->assertSame(0.0, $result->getCostModelCurrency());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(
        string $referenceCurrency = 'EUR',
        array $currencyRates = [],
        ?EventDispatcherInterface $dispatcher = null,
        ?ModelCapabilityRegistry $capabilityRegistry = null,
    ): TokenAccountingService {
        return new TokenAccountingService(
            modelRepo: $this->modelRepo,
            em: $this->em,
            referenceCurrency: $referenceCurrency,
            currencyRates: $currencyRates,
            cache: null,
            dispatcher: $dispatcher,
            capabilityRegistry: $capabilityRegistry,
        );
    }
}
