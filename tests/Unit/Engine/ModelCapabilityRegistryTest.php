<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chat;

use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use PHPUnit\Framework\TestCase;

class ModelCapabilityRegistryTest extends TestCase
{
    public function testGetCapabilitiesReturnsSomethingValid(): void
    {
        $registry = new ModelCapabilityRegistry();
        $capabilities = $registry->getCapabilities('non-existent-model');

        $this->assertInstanceOf(ModelCapabilities::class, $capabilities);
        $this->assertSame('non-existent-model', $capabilities->model);
    }

    public function testDefaultCapabilities(): void
    {
        $registry = new ModelCapabilityRegistry();
        $caps = $registry->getCapabilities('non-existent-model');

        // Defaults pour un modèle inconnu
        $this->assertTrue($caps->supportsStreaming);
        $this->assertTrue($caps->supportsFunctionCalling);
        $this->assertTrue($caps->supportsSystemPrompt);
        $this->assertFalse($caps->supportsThinking);
        $this->assertFalse($caps->supportsSafetySettings);
        $this->assertFalse($caps->supportsTopK);
        $this->assertFalse($caps->supportsVision);
        $this->assertFalse($caps->supportsParallelToolCalls);
        $this->assertFalse($caps->supportsResponseSchema);
        $this->assertNull($caps->maxInputTokens);
        $this->assertNull($caps->maxOutputTokens);
        $this->assertNull($caps->deprecatedAt);
        $this->assertNull($caps->pricingInput);
        $this->assertNull($caps->pricingOutput);
    }

    public function testIsKnownModel(): void
    {
        $registry = new ModelCapabilityRegistry();
        $this->assertIsBool($registry->isKnownModel('anything'));
    }

    public function testKnownGeminiModel(): void
    {
        $registry = new ModelCapabilityRegistry();

        if (!$registry->isKnownModel('gemini-2.5-flash')) {
            $this->markTestSkipped('gemini-2.5-flash not in registry');
        }

        $caps = $registry->getCapabilities('gemini-2.5-flash');
        $this->assertSame('gemini', $caps->provider);
        $this->assertTrue($caps->supportsVision);
        $this->assertTrue($caps->supportsParallelToolCalls);
        $this->assertTrue($caps->supportsResponseSchema);
        $this->assertTrue($caps->supportsThinking);
        $this->assertSame(1000000, $caps->maxInputTokens);
        $this->assertSame(65536, $caps->maxOutputTokens);
    }

    public function testGetEffectiveMaxInputTokensFallback(): void
    {
        $caps = new ModelCapabilities(
            model: 'test',
            provider: 'test',
            maxInputTokens: null,
            contextWindow: 128000,
        );

        $this->assertSame(128000, $caps->getEffectiveMaxInputTokens());
    }

    public function testIsDeprecated(): void
    {
        $caps = new ModelCapabilities(
            model: 'test',
            provider: 'test',
            deprecatedAt: '2020-01-01',
        );

        $this->assertTrue($caps->isDeprecated());
        $this->assertFalse($caps->isDeprecated(new \DateTimeImmutable('2019-12-31')));
    }

    public function testGetModelsForProvider(): void
    {
        $registry = new ModelCapabilityRegistry();
        $geminiModels = $registry->getModelsForProvider('gemini');
        $this->assertIsArray($geminiModels);
    }
}
