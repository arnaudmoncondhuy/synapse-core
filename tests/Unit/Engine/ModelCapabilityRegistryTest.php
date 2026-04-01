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
        $this->assertNull($caps->vertexRegion);
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
        $this->assertSame('google_vertex_ai', $caps->provider);
        $this->assertTrue($caps->supportsVision);
        $this->assertTrue($caps->supportsParallelToolCalls);
        $this->assertTrue($caps->supportsResponseSchema);
        $this->assertTrue($caps->supportsThinking);
        $this->assertSame(1048576, $caps->maxInputTokens);
        $this->assertSame(65535, $caps->maxOutputTokens);
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
        $geminiModels = $registry->getModelsForProvider('google_vertex_ai');
        $this->assertIsArray($geminiModels);
    }

    public function testVertexRegionsOnPreviewModels(): void
    {
        $registry = new ModelCapabilityRegistry();

        foreach (['gemini-3-flash-preview', 'gemini-3.1-pro-preview', 'gemini-3.1-flash-lite-preview'] as $modelId) {
            if (!$registry->isKnownModel($modelId)) {
                continue;
            }
            $caps = $registry->getCapabilities($modelId);
            $this->assertSame(['global'], $caps->vertexRegions, "Le modèle $modelId doit avoir vertex_regions: [global]");
        }
    }

    public function testVertexRegionsOnStableModels(): void
    {
        $registry = new ModelCapabilityRegistry();

        foreach (['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite'] as $modelId) {
            if (!$registry->isKnownModel($modelId)) {
                continue;
            }
            $caps = $registry->getCapabilities($modelId);
            $this->assertNotEmpty($caps->vertexRegions, "Le modèle $modelId doit avoir des vertex_regions listées");
            $this->assertContains('us-central1', $caps->vertexRegions, "Le modèle $modelId doit être disponible en us-central1");
        }
    }

    public function testNewModelsRegistered(): void
    {
        $registry = new ModelCapabilityRegistry();

        $newModels = [
            'gemini-3.1-flash-image-preview',
            'gemini-3-pro-image-preview',
            'gemini-2.5-flash-image',
            'gemini-embedding-2-preview',
        ];

        foreach ($newModels as $modelId) {
            $this->assertTrue($registry->isKnownModel($modelId), "Le modèle $modelId doit être connu");
        }
    }

    public function testDeprecatedModelsRemoved(): void
    {
        $registry = new ModelCapabilityRegistry();

        foreach (['gemini-2.0-flash', 'gemini-2.0-flash-lite'] as $modelId) {
            $this->assertFalse($registry->isKnownModel($modelId), "Le modèle déprécié $modelId ne doit plus être dans le YAML");
        }
    }
}
