<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Agent\SynapseAgentBuilder;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use PHPUnit\Framework\TestCase;

class SynapseAgentBuilderTest extends TestCase
{
    private $chatService;
    private $capabilityRegistry;
    private $builder;

    protected function setUp(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->capabilityRegistry = $this->createMock(ModelCapabilityRegistry::class);
        $this->builder = new SynapseAgentBuilder($this->chatService, $this->capabilityRegistry);
    }

    public function testBuildThrowsExceptionIfNoModel(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Un modèle doit être défini');
        $this->builder->build();
    }

    public function testBuildThrowsExceptionIfToolNotSupported(): void
    {
        $caps = new ModelCapabilities('gpt-4', 'openai', supportsFunctionCalling: false);

        $this->capabilityRegistry->method('getCapabilities')->willReturn($caps);

        $this->builder->withModel('gpt-4')->withAllowedTools(['test_tool']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("ne supporte pas l'appel d'outils");
        $this->builder->build();
    }

    public function testBuildSuccess(): void
    {
        $caps = new ModelCapabilities('gpt-4', 'openai', supportsFunctionCalling: true);

        $this->capabilityRegistry->method('getCapabilities')->willReturn($caps);

        $agent = $this->builder
            ->withModel('gpt-4')
            ->withTemperature(0.5)
            ->withSystemPrompt('You are a helper')
            ->build();

        $this->assertInstanceOf(SynapseAgent::class, $agent);
        $this->assertSame('gpt-4', $agent->getPreset()->getModel());
        $this->assertSame(0.5, $agent->getPreset()->getGenerationTemperature());
    }
}
