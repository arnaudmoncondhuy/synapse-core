<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\RgpdAwareInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseCore\Security\RgpdEvaluator;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\RgpdInfo;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use PHPUnit\Framework\TestCase;

class RgpdEvaluatorTest extends TestCase
{
    public function testEvaluateReturnsNullWhenClientNotFound(): void
    {
        $clientRegistry = $this->createStub(LlmClientRegistry::class);
        $clientRegistry->method('getClientByProvider')->willThrowException(new \RuntimeException());

        $evaluator = new RgpdEvaluator($clientRegistry, $this->createStub(SynapseProviderRepository::class));

        $preset = $this->createStub(SynapseModelPreset::class);
        $preset->method('getProviderName')->willReturn('unknown');

        $this->assertNull($evaluator->evaluate($preset));
    }

    public function testEvaluateReturnsNullWhenClientNotRgpdAware(): void
    {
        $client = $this->createStub(LlmClientInterface::class);
        $clientRegistry = $this->createStub(LlmClientRegistry::class);
        $clientRegistry->method('getClientByProvider')->willReturn($client);

        $evaluator = new RgpdEvaluator($clientRegistry, $this->createStub(SynapseProviderRepository::class));

        $preset = $this->createStub(SynapseModelPreset::class);
        $preset->method('getProviderName')->willReturn('test');

        $this->assertNull($evaluator->evaluate($preset));
    }

    public function testEvaluateReturnsRgpdInfo(): void
    {
        $rgpdInfo = new RgpdInfo('compliant', 'EU', 'Hosted in EU');

        $client = $this->createMock(RgpdAwareLlmClient::class);
        $client->method('getRgpdInfo')->willReturn($rgpdInfo);

        $clientRegistry = $this->createStub(LlmClientRegistry::class);
        $clientRegistry->method('getClientByProvider')->willReturn($client);

        $provider = $this->createStub(SynapseProvider::class);
        $provider->method('getCredentials')->willReturn(['key' => 'secret']);

        $providerRepo = $this->createStub(SynapseProviderRepository::class);
        $providerRepo->method('findByName')->willReturn($provider);

        $evaluator = new RgpdEvaluator($clientRegistry, $providerRepo);

        $preset = $this->createStub(SynapseModelPreset::class);
        $preset->method('getProviderName')->willReturn('google');
        $preset->method('getProviderOptions')->willReturn(['region' => 'eu']);
        $preset->method('getModel')->willReturn('gemini-pro');

        $result = $evaluator->evaluate($preset);
        $this->assertSame('compliant', $result->status);
    }

    public function testGetWarningsFiltersCompliant(): void
    {
        $compliantInfo = new RgpdInfo('compliant', 'EU', 'OK');
        $dangerInfo = new RgpdInfo('danger', 'US', 'No DPA');

        $client = $this->createMock(RgpdAwareLlmClient::class);
        $client->method('getRgpdInfo')
            ->willReturnOnConsecutiveCalls($compliantInfo, $dangerInfo);

        $clientRegistry = $this->createStub(LlmClientRegistry::class);
        $clientRegistry->method('getClientByProvider')->willReturn($client);

        $providerRepo = $this->createStub(SynapseProviderRepository::class);
        $providerRepo->method('findByName')->willReturn(null);

        $evaluator = new RgpdEvaluator($clientRegistry, $providerRepo);

        $preset1 = $this->createStub(SynapseModelPreset::class);
        $preset1->method('getProviderName')->willReturn('p');
        $preset1->method('getProviderOptions')->willReturn([]);
        $preset1->method('getModel')->willReturn('m1');

        $preset2 = $this->createStub(SynapseModelPreset::class);
        $preset2->method('getProviderName')->willReturn('p');
        $preset2->method('getProviderOptions')->willReturn([]);
        $preset2->method('getModel')->willReturn('m2');

        $warnings = $evaluator->getWarnings([$preset1, $preset2]);

        $this->assertCount(1, $warnings);
        $this->assertSame('danger', $warnings[0]['rgpd']->status);
    }
}

/**
 * Helper interface combining LlmClientInterface and RgpdAwareInterface for mocking.
 */
interface RgpdAwareLlmClient extends LlmClientInterface, RgpdAwareInterface
{
}
