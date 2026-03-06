<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent\PresetValidator;

use ArnaudMoncondhuy\SynapseCore\Agent\PresetValidator\PresetValidatorAgent;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use PHPUnit\Framework\TestCase;

class PresetValidatorAgentTest extends TestCase
{
    private $chatService;
    private $debugLogRepo;
    private $capabilityRegistry;
    private $providerRepo;
    private $configProvider;
    private $agent;

    protected function setUp(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->debugLogRepo = $this->createMock(SynapseDebugLogRepository::class);
        $this->capabilityRegistry = $this->createMock(ModelCapabilityRegistry::class);
        $this->providerRepo = $this->createMock(SynapseProviderRepository::class);
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);

        $this->agent = new PresetValidatorAgent(
            $this->chatService,
            $this->debugLogRepo,
            $this->capabilityRegistry,
            $this->providerRepo,
            $this->configProvider
        );
    }

    public function testExecuteConfigCheckStepWithValidConfig(): void
    {
        $preset = new SynapsePreset();
        $preset->setProviderName('openai');
        $preset->setModel('gpt-4');

        $provider = $this->createMock(SynapseProvider::class);
        $provider->method('isEnabled')->willReturn(true);
        $provider->method('isConfigured')->willReturn(true);

        $this->providerRepo->method('findByName')->willReturn($provider);
        $this->capabilityRegistry->method('isKnownModel')->willReturn(true);

        $report = [];
        $this->agent->runStep(1, $preset, $report);

        $this->assertTrue($report['config_ok']);
        $this->assertEmpty($report['config_errors']);
    }

    public function testExecuteConfigCheckStepWithInvalidProvider(): void
    {
        $preset = new SynapsePreset();
        $preset->setProviderName('unknown');

        $this->providerRepo->method('findByName')->willReturn(null);

        $report = [];
        $this->agent->runStep(1, $preset, $report);

        $this->assertFalse($report['config_ok']);
        $this->assertStringContainsString('Provider "unknown" introuvable', $report['config_errors'][0]);
    }
}
