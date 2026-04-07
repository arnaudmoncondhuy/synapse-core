<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\AbstractAgent;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Agent\ResolvedAgentDescriptor;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTone;
use PHPUnit\Framework\TestCase;

class ResolvedAgentDescriptorTest extends TestCase
{
    public function testFromEntityExtractsAllProperties(): void
    {
        $preset = new SynapseModelPreset();
        $preset->setKey('gemini_image');

        $tone = new SynapseTone();
        $tone->setKey('formal');

        $entity = new SynapseAgent();
        $entity->setKey('support_client');
        $entity->setEmoji('🎧');
        $entity->setSystemPrompt('Tu es un agent de support.');
        $entity->setAllowedToolNames(['web_search']);
        $entity->setModelPreset($preset);
        $entity->setTone($tone);

        $descriptor = ResolvedAgentDescriptor::fromEntity($entity);

        $this->assertSame('support_client', $descriptor->name);
        $this->assertNull($descriptor->id); // Non persisté → null
        $this->assertSame('Tu es un agent de support.', $descriptor->systemPrompt);
        $this->assertSame(['web_search'], $descriptor->allowedToolNames);
        $this->assertSame('gemini_image', $descriptor->presetKey);
        $this->assertSame('formal', $descriptor->toneKey);
        $this->assertSame('🎧', $descriptor->emoji);
        $this->assertSame('db', $descriptor->source);
    }

    public function testFromEntityWithNullOptionals(): void
    {
        $entity = new SynapseAgent();
        $entity->setKey('basic');
        $entity->setSystemPrompt('Hello');

        $descriptor = ResolvedAgentDescriptor::fromEntity($entity);

        $this->assertNull($descriptor->presetKey);
        $this->assertNull($descriptor->toneKey);
        $this->assertSame('db', $descriptor->source);
    }

    public function testFromCodeAgentWithAbstractAgent(): void
    {
        $agent = new class extends AbstractAgent {
            public function getName(): string
            {
                return 'image_gen';
            }

            public function getDescription(): string
            {
                return 'Generates images';
            }

            public function getSystemPrompt(): string
            {
                return 'Tu génères des images.';
            }

            public function getAllowedToolNames(): array
            {
                return ['image_tool'];
            }

            public function getPresetKey(): ?string
            {
                return 'gemini_image';
            }

            public function getToneKey(): ?string
            {
                return 'creative';
            }

            public function getEmoji(): string
            {
                return '🎨';
            }

            protected function execute(Input $input, AgentContext $context): Output
            {
                return Output::ofData([]);
            }
        };

        $descriptor = ResolvedAgentDescriptor::fromCodeAgent($agent);

        $this->assertSame('image_gen', $descriptor->name);
        $this->assertNull($descriptor->id);
        $this->assertSame('Tu génères des images.', $descriptor->systemPrompt);
        $this->assertSame(['image_tool'], $descriptor->allowedToolNames);
        $this->assertSame('gemini_image', $descriptor->presetKey);
        $this->assertSame('creative', $descriptor->toneKey);
        $this->assertSame('🎨', $descriptor->emoji);
        $this->assertSame('code', $descriptor->source);
    }

    public function testFromCodeAgentWithDefaults(): void
    {
        $agent = new class extends AbstractAgent {
            public function getName(): string
            {
                return 'orchestrator';
            }

            public function getDescription(): string
            {
                return 'Orchestrates';
            }

            protected function execute(Input $input, AgentContext $context): Output
            {
                return Output::ofData([]);
            }
        };

        $descriptor = ResolvedAgentDescriptor::fromCodeAgent($agent);

        $this->assertSame('orchestrator', $descriptor->name);
        $this->assertSame('', $descriptor->systemPrompt);
        $this->assertSame([], $descriptor->allowedToolNames);
        $this->assertNull($descriptor->presetKey);
        $this->assertNull($descriptor->toneKey);
        $this->assertSame("\u{1F916}", $descriptor->emoji);
        $this->assertSame('code', $descriptor->source);
    }

    public function testFromRawAgentInterfaceGivesMinimalDescriptor(): void
    {
        $agent = new class implements AgentInterface {
            public function getName(): string
            {
                return 'raw_agent';
            }

            public function getLabel(): string
            {
                return 'Raw Agent';
            }

            public function getDescription(): string
            {
                return 'No abstract';
            }

            public function call(Input $input, array $options = []): Output
            {
                return Output::ofData([]);
            }
        };

        $descriptor = ResolvedAgentDescriptor::fromCodeAgent($agent);

        $this->assertSame('raw_agent', $descriptor->name);
        $this->assertNull($descriptor->id);
        $this->assertSame('', $descriptor->systemPrompt);
        $this->assertSame([], $descriptor->allowedToolNames);
        $this->assertNull($descriptor->presetKey);
        $this->assertNull($descriptor->toneKey);
        $this->assertSame('code', $descriptor->source);
    }
}
