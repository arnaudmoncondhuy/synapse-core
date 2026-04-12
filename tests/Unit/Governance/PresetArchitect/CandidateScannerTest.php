<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Governance\PresetArchitect;

use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect\CandidateScanner;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ModelRange;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModel;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use PHPUnit\Framework\TestCase;

class CandidateScannerTest extends TestCase
{
    private SynapseProviderRepository $providerRepo;
    private ModelCapabilityRegistry $capabilityRegistry;
    private SynapseModelRepository $modelRepo;

    protected function setUp(): void
    {
        $this->providerRepo = $this->createStub(SynapseProviderRepository::class);
        $this->capabilityRegistry = $this->createStub(ModelCapabilityRegistry::class);
        $this->modelRepo = $this->createStub(SynapseModelRepository::class);
        $this->modelRepo->method('findAll')->willReturn([]);
    }

    public function testReturnsOnlyTextGenerationModels(): void
    {
        $provider = $this->buildProvider('anthropic', 'Anthropic', true);
        $this->providerRepo->method('findEnabled')->willReturn([$provider]);

        $this->capabilityRegistry->method('getModelsForProvider')
            ->with('anthropic')
            ->willReturn(['claude-sonnet', 'bge-m3']);

        $this->capabilityRegistry->method('getCapabilities')->willReturnCallback(
            fn (string $model) => match ($model) {
                'claude-sonnet' => new ModelCapabilities(model: 'claude-sonnet', provider: 'anthropic', range: ModelRange::BALANCED, supportsTextGeneration: true),
                'bge-m3' => new ModelCapabilities(model: 'bge-m3', provider: 'anthropic', range: ModelRange::SPECIALIZED, supportsTextGeneration: false, supportsEmbedding: true),
                default => throw new \LogicException("Unexpected model: $model"),
            }
        );

        $scanner = new CandidateScanner($this->providerRepo, $this->capabilityRegistry, $this->modelRepo);
        $candidates = $scanner->scan();

        $this->assertCount(1, $candidates);
        $this->assertSame('claude-sonnet', $candidates[0]['modelId']);
    }

    public function testFiltersDeprecatedModels(): void
    {
        $provider = $this->buildProvider('anthropic', 'Anthropic', true);
        $this->providerRepo->method('findEnabled')->willReturn([$provider]);

        $this->capabilityRegistry->method('getModelsForProvider')
            ->willReturn(['claude-old', 'claude-new']);

        $this->capabilityRegistry->method('getCapabilities')->willReturnCallback(
            fn (string $model) => match ($model) {
                'claude-old' => new ModelCapabilities(model: 'claude-old', provider: 'anthropic', supportsTextGeneration: true, deprecatedAt: '2020-01-01'),
                'claude-new' => new ModelCapabilities(model: 'claude-new', provider: 'anthropic', supportsTextGeneration: true),
                default => throw new \LogicException("Unexpected model: $model"),
            }
        );

        $scanner = new CandidateScanner($this->providerRepo, $this->capabilityRegistry, $this->modelRepo);
        $candidates = $scanner->scan();

        $this->assertCount(1, $candidates);
        $this->assertSame('claude-new', $candidates[0]['modelId']);
    }

    public function testFiltersDisabledModels(): void
    {
        $provider = $this->buildProvider('anthropic', 'Anthropic', true);
        $this->providerRepo->method('findEnabled')->willReturn([$provider]);

        $this->capabilityRegistry->method('getModelsForProvider')
            ->willReturn(['claude-enabled', 'claude-disabled']);

        $this->capabilityRegistry->method('getCapabilities')->willReturnCallback(
            fn (string $model) => new ModelCapabilities(model: $model, provider: 'anthropic', supportsTextGeneration: true)
        );

        // claude-disabled est désactivé en DB
        $disabledModel = new SynapseModel();
        $disabledModel->setModelId('claude-disabled')
            ->setProviderName('anthropic')
            ->setLabel('Disabled')
            ->setIsEnabled(false);
        $this->modelRepo = $this->createStub(SynapseModelRepository::class);
        $this->modelRepo->method('findAll')->willReturn([$disabledModel]);

        $scanner = new CandidateScanner($this->providerRepo, $this->capabilityRegistry, $this->modelRepo);
        $candidates = $scanner->scan();

        $this->assertCount(1, $candidates);
        $this->assertSame('claude-enabled', $candidates[0]['modelId']);
    }

    public function testProviderFilterWorks(): void
    {
        $anthropic = $this->buildProvider('anthropic', 'Anthropic', true);
        $ovh = $this->buildProvider('ovh', 'OVH', true);
        $this->providerRepo->method('findEnabled')->willReturn([$anthropic, $ovh]);

        $this->capabilityRegistry->method('getModelsForProvider')->willReturnCallback(
            fn (string $p) => match ($p) {
                'anthropic' => ['claude-sonnet'],
                'ovh' => ['gpt-oss'],
                default => [],
            }
        );

        $this->capabilityRegistry->method('getCapabilities')->willReturnCallback(
            fn (string $model) => new ModelCapabilities(model: $model, provider: 'test', supportsTextGeneration: true)
        );

        $scanner = new CandidateScanner($this->providerRepo, $this->capabilityRegistry, $this->modelRepo);
        $candidates = $scanner->scan('ovh');

        $this->assertCount(1, $candidates);
        $this->assertSame('gpt-oss', $candidates[0]['modelId']);
    }

    public function testSkipsUnconfiguredProviders(): void
    {
        $unconfigured = $this->buildProvider('anthropic', 'Anthropic', false);
        $this->providerRepo->method('findEnabled')->willReturn([$unconfigured]);

        $scanner = new CandidateScanner($this->providerRepo, $this->capabilityRegistry, $this->modelRepo);
        $candidates = $scanner->scan();

        $this->assertCount(0, $candidates);
    }

    private function buildProvider(string $name, string $label, bool $configured): SynapseProvider
    {
        $provider = new SynapseProvider();
        $provider->setName($name);
        $provider->setLabel($label);
        if ($configured) {
            $provider->setCredentials(['api_key' => 'secret']);
        }

        return $provider;
    }
}
