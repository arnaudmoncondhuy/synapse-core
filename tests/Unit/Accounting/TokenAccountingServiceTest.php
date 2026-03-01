<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Accounting;

use ArnaudMoncondhuy\SynapseCore\Core\Accounting\TokenAccountingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseLlmCall;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TokenAccountingServiceTest extends TestCase
{
    /**
     * Test calculateCost() — logique pure sans dépendances externes
     */
    public function testCalculateCost_basic(): void
    {
        $service = $this->createServiceWithoutDependencies();

        $usage = ['prompt_tokens' => 1_000_000, 'completion_tokens' => 0, 'thinking_tokens' => 0];
        $pricing = ['input' => 1.0, 'output' => 1.0];

        $cost = $service->calculateCost($usage, $pricing);
        $this->assertSame(1.0, $cost);
    }

    public function testCalculateCost_withCompletionTokens(): void
    {
        $service = $this->createServiceWithoutDependencies();

        $usage = ['prompt_tokens' => 500_000, 'completion_tokens' => 500_000, 'thinking_tokens' => 0];
        $pricing = ['input' => 1.0, 'output' => 2.0];

        // (500k * 1.0 + 500k * 2.0) / 1M = 1.5
        $cost = $service->calculateCost($usage, $pricing);
        $this->assertSame(1.5, $cost);
    }

    public function testCalculateCost_withThinkingTokens(): void
    {
        $service = $this->createServiceWithoutDependencies();

        $usage = ['prompt_tokens' => 500_000, 'completion_tokens' => 200_000, 'thinking_tokens' => 100_000];
        $pricing = ['input' => 0.5, 'output' => 1.0];

        // (500k * 0.5 + (200k + 100k) * 1.0) / 1M = (250 + 300) / 1000 = 0.55
        $cost = $service->calculateCost($usage, $pricing);
        $this->assertEqualsWithDelta(0.55, $cost, 0.00001);
    }

    public function testCalculateCost_returnsZero_whenZeroTokens(): void
    {
        $service = $this->createServiceWithoutDependencies();

        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'thinking_tokens' => 0];
        $pricing = ['input' => 1.0, 'output' => 1.0];

        $cost = $service->calculateCost($usage, $pricing);
        $this->assertSame(0.0, $cost);
    }

    public function testCalculateCost_fractionOfMillion(): void
    {
        $service = $this->createServiceWithoutDependencies();

        $usage = ['prompt_tokens' => 1_000, 'completion_tokens' => 0, 'thinking_tokens' => 0];
        $pricing = ['input' => 1.0, 'output' => 1.0];

        // 1000 * 1.0 / 1M = 0.001
        $cost = $service->calculateCost($usage, $pricing);
        $this->assertSame(0.001, $cost);
    }

    public function testCalculateCost_verySmallCost(): void
    {
        $service = $this->createServiceWithoutDependencies();

        $usage = ['prompt_tokens' => 100, 'completion_tokens' => 50, 'thinking_tokens' => 0];
        $pricing = ['input' => 0.075, 'output' => 0.3];

        // (100 * 0.075 + 50 * 0.3) / 1M = (7.5 + 15) / 1M = 0.0000225
        // Note: floating point rounding may cause slight differences
        $cost = $service->calculateCost($usage, $pricing);
        $this->assertEqualsWithDelta(0.0000225, $cost, 0.000001);
    }

    /**
     * Test convertToReferenceCurrency() — logique pure
     */
    public function testConvertToReferenceCurrency_sameCurrency_noConversion(): void
    {
        $service = new TokenAccountingService(
            modelRepo: $this->createMock(SynapseModelRepository::class),
            em: $this->createMock(EntityManagerInterface::class),
            referenceCurrency: 'EUR',
            currencyRates: [],
        );

        // EUR -> EUR with no rate configured
        $result = $service->convertToReferenceCurrency(100.0, 'EUR');
        $this->assertSame(100.0, $result);
    }

    public function testConvertToReferenceCurrency_withExchangeRate(): void
    {
        $service = new TokenAccountingService(
            modelRepo: $this->createMock(SynapseModelRepository::class),
            em: $this->createMock(EntityManagerInterface::class),
            referenceCurrency: 'EUR',
            currencyRates: ['USD' => 0.91],  // 1 USD = 0.91 EUR
        );

        $result = $service->convertToReferenceCurrency(1.0, 'USD');
        $this->assertSame(0.91, $result);
    }

    public function testConvertToReferenceCurrency_unknownCurrency_returnsOriginal(): void
    {
        $service = new TokenAccountingService(
            modelRepo: $this->createMock(SynapseModelRepository::class),
            em: $this->createMock(EntityManagerInterface::class),
            referenceCurrency: 'EUR',
            currencyRates: [],
        );

        // Unknown currency XYZ, no rate available -> return original amount
        $result = $service->convertToReferenceCurrency(50.0, 'XYZ');
        $this->assertSame(50.0, $result);
    }

    public function testConvertToReferenceCurrency_multipleRates(): void
    {
        $service = new TokenAccountingService(
            modelRepo: $this->createMock(SynapseModelRepository::class),
            em: $this->createMock(EntityManagerInterface::class),
            referenceCurrency: 'EUR',
            currencyRates: [
                'USD' => 0.91,
                'GBP' => 1.17,
                'CHF' => 1.05,
            ],
        );

        $this->assertSame(0.91, $service->convertToReferenceCurrency(1.0, 'USD'));
        $this->assertSame(1.17, $service->convertToReferenceCurrency(1.0, 'GBP'));
        $this->assertSame(1.05, $service->convertToReferenceCurrency(1.0, 'CHF'));
    }

    /**
     * Test logUsage() avec mocks EntityManager et Repository
     */
    public function testLogUsage_returnsSynapseLlmCall(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new TokenAccountingService(
            modelRepo: $this->createMock(SynapseModelRepository::class),
            em: $em,
            referenceCurrency: 'EUR',
            currencyRates: [],
        );

        $result = $service->logUsage(
            module: 'chat',
            action: 'ask',
            model: 'gemini-2.0-flash',
            usage: [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'thinking_tokens' => 0,
            ],
        );

        $this->assertInstanceOf(SynapseLlmCall::class, $result);
    }

    public function testLogUsage_callIdIsNotEmpty(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new TokenAccountingService(
            modelRepo: $this->createMock(SynapseModelRepository::class),
            em: $em,
            referenceCurrency: 'EUR',
            currencyRates: [],
        );

        $llmCall = $service->logUsage(
            module: 'chat',
            action: 'ask',
            model: 'gemini-2.0-flash',
            usage: [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'thinking_tokens' => 0,
            ],
        );

        $this->assertNotEmpty($llmCall->getCallId());
        $this->assertIsString($llmCall->getCallId());
    }

    public function testLogUsage_persistsToEntityManager(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new TokenAccountingService(
            modelRepo: $this->createMock(SynapseModelRepository::class),
            em: $em,
            referenceCurrency: 'EUR',
            currencyRates: [],
        );

        $service->logUsage(
            module: 'chat',
            action: 'ask',
            model: 'gemini-2.0-flash',
            usage: [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'thinking_tokens' => 0,
            ],
        );

        // Mock already verified with expects()
    }

    public function testGetReferenceCurrency(): void
    {
        $service = new TokenAccountingService(
            modelRepo: $this->createMock(SynapseModelRepository::class),
            em: $this->createMock(EntityManagerInterface::class),
            referenceCurrency: 'EUR',
            currencyRates: [],
        );

        $this->assertSame('EUR', $service->getReferenceCurrency());
    }

    /**
     * Helper: create a service without mocking for pure logic tests
     */
    private function createServiceWithoutDependencies(): TokenAccountingService
    {
        return new TokenAccountingService(
            modelRepo: $this->createMock(SynapseModelRepository::class),
            em: $this->createMock(EntityManagerInterface::class),
            referenceCurrency: 'EUR',
            currencyRates: [],
        );
    }
}
