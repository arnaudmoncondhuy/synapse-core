<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use PHPUnit\Framework\TestCase;

class SynapseRuntimeConfigTest extends TestCase
{
    public function testFromArrayWithMinimalData(): void
    {
        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'gemini-pro',
            'provider' => 'google',
        ]);

        $this->assertSame('gemini-pro', $config->model);
        $this->assertSame('google', $config->provider);
        $this->assertTrue($config->streamingEnabled);
        $this->assertFalse($config->debugMode);
        $this->assertSame(5, $config->maxTurns);
        $this->assertSame([], $config->disabledCapabilities);
    }

    public function testFromArrayProviderNameAlias(): void
    {
        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'test',
            'provider_name' => 'ovh',
        ]);

        $this->assertSame('ovh', $config->provider);
    }

    public function testFromArrayWithFullData(): void
    {
        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'gpt-4',
            'provider' => 'openai',
            'preset_id' => 42,
            'preset_name' => 'my-preset',
            'generation_config' => ['temperature' => 0.5],
            'thinking' => ['enabled' => true, 'budget' => 2048],
            'safety_settings' => ['enabled' => true],
            'streaming_enabled' => false,
            'debug_mode' => true,
            'max_turns' => 10,
            'disabled_capabilities' => ['streaming'],
            'system_prompt' => 'Be helpful',
            'master_prompt' => 'Master',
            'master_prompt_stateless' => false,
            'active_tone' => 'formal',
            'agent_id' => 7,
            'agent_name' => 'support',
            'agent_emoji' => '🤖',
            'pricing_input' => 2.5,
            'pricing_output' => 10.0,
            'provider_region' => 'eu-west-1',
        ]);

        $this->assertSame(42, $config->presetId);
        $this->assertSame('my-preset', $config->presetName);
        $this->assertSame(0.5, $config->generation->temperature);
        $this->assertTrue($config->thinking->enabled);
        $this->assertSame(2048, $config->thinking->budget);
        $this->assertTrue($config->safety->enabled);
        $this->assertFalse($config->streamingEnabled);
        $this->assertTrue($config->debugMode);
        $this->assertSame(10, $config->maxTurns);
        $this->assertSame(['streaming'], $config->disabledCapabilities);
        $this->assertSame('Be helpful', $config->systemPrompt);
        $this->assertSame('formal', $config->activeTone);
        $this->assertSame(7, $config->agentId);
        $this->assertSame(2.5, $config->pricingInput);
        $this->assertSame(10.0, $config->pricingOutput);
        $this->assertSame('eu-west-1', $config->providerRegion);
    }

    public function testToArrayRoundtrip(): void
    {
        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'test-model',
            'provider' => 'test-provider',
            'active_tone' => 'casual',
        ]);

        $array = $config->toArray();
        $restored = SynapseRuntimeConfig::fromArray($array);

        $this->assertSame($config->model, $restored->model);
        $this->assertSame($config->provider, $restored->provider);
        $this->assertSame($config->activeTone, $restored->activeTone);
    }

    public function testIsStreamingEffective(): void
    {
        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'm',
            'provider' => 'p',
            'streaming_enabled' => true,
        ]);
        $this->assertTrue($config->isStreamingEffective());

        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'm',
            'provider' => 'p',
            'streaming_enabled' => false,
        ]);
        $this->assertFalse($config->isStreamingEffective());

        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'm',
            'provider' => 'p',
            'streaming_enabled' => true,
            'disabled_capabilities' => ['streaming'],
        ]);
        $this->assertFalse($config->isStreamingEffective());
    }

    public function testIsFunctionCallingEnabled(): void
    {
        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'm',
            'provider' => 'p',
        ]);
        $this->assertTrue($config->isFunctionCallingEnabled());

        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'm',
            'provider' => 'p',
            'disabled_capabilities' => ['function_calling'],
        ]);
        $this->assertFalse($config->isFunctionCallingEnabled());
    }

    public function testWithActiveToneReturnsNewInstance(): void
    {
        $original = SynapseRuntimeConfig::fromArray([
            'model' => 'm',
            'provider' => 'p',
            'active_tone' => 'formal',
        ]);

        $modified = $original->withActiveTone('casual');

        $this->assertSame('formal', $original->activeTone);
        $this->assertSame('casual', $modified->activeTone);
        $this->assertSame('m', $modified->model);
    }

    public function testWithAgentInfoReturnsNewInstance(): void
    {
        $original = SynapseRuntimeConfig::fromArray([
            'model' => 'm',
            'provider' => 'p',
        ]);

        $modified = $original->withAgentInfo(5, 'bot', '🤖');

        $this->assertNull($original->agentId);
        $this->assertSame(5, $modified->agentId);
        $this->assertSame('bot', $modified->agentName);
        $this->assertSame('🤖', $modified->agentEmoji);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $config = SynapseRuntimeConfig::fromArray([]);

        $this->assertSame('', $config->model);
        $this->assertSame('', $config->provider);
    }
}
