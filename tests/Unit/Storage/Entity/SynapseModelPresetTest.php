<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use PHPUnit\Framework\TestCase;

class SynapseModelPresetTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $preset = new SynapseModelPreset();

        $this->assertNull($preset->getId());
        $this->assertSame('', $preset->getKey());
        $this->assertSame('Preset par défaut', $preset->getName());
        $this->assertFalse($preset->isActive());
        $this->assertSame('', $preset->getProviderName());
        $this->assertSame('', $preset->getModel());
        $this->assertNull($preset->getProviderOptions());
        $this->assertSame(1.0, $preset->getGenerationTemperature());
        $this->assertSame(0.95, $preset->getGenerationTopP());
        $this->assertSame(40, $preset->getGenerationTopK());
        $this->assertNull($preset->getGenerationMaxOutputTokens());
        $this->assertNull($preset->getGenerationStopSequences());
        $this->assertTrue($preset->isStreamingEnabled());
        $this->assertInstanceOf(\DateTimeImmutable::class, $preset->getUpdatedAt());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $preset = new SynapseModelPreset();

        $preset->setKey('fast_creative')
            ->setName('Fast Creative')
            ->setIsActive(true)
            ->setProviderName('openai')
            ->setModel('gpt-4o')
            ->setProviderOptions(['reasoningEffort' => 'high'])
            ->setGenerationTemperature(0.8)
            ->setGenerationTopP(0.9)
            ->setGenerationTopK(50)
            ->setGenerationMaxOutputTokens(4096)
            ->setGenerationStopSequences(['STOP', 'END'])
            ->setStreamingEnabled(false);

        $this->assertSame('fast_creative', $preset->getKey());
        $this->assertSame('Fast Creative', $preset->getName());
        $this->assertTrue($preset->isActive());
        $this->assertSame('openai', $preset->getProviderName());
        $this->assertSame('gpt-4o', $preset->getModel());
        $this->assertSame(['reasoningEffort' => 'high'], $preset->getProviderOptions());
        $this->assertSame(0.8, $preset->getGenerationTemperature());
        $this->assertSame(0.9, $preset->getGenerationTopP());
        $this->assertSame(50, $preset->getGenerationTopK());
        $this->assertSame(4096, $preset->getGenerationMaxOutputTokens());
        $this->assertSame(['STOP', 'END'], $preset->getGenerationStopSequences());
        $this->assertFalse($preset->isStreamingEnabled());
    }

    public function testToArrayBasic(): void
    {
        $preset = new SynapseModelPreset();
        $preset->setProviderName('gemini')
            ->setModel('gemini-2.0-flash')
            ->setGenerationTemperature(0.7)
            ->setGenerationTopP(0.9)
            ->setGenerationTopK(30);

        $arr = $preset->toArray();

        $this->assertSame('gemini', $arr['provider']);
        $this->assertSame('gemini-2.0-flash', $arr['model']);
        $this->assertSame(0.7, $arr['generation_config']['temperature']);
        $this->assertSame(0.9, $arr['generation_config']['top_p']);
        $this->assertSame(30, $arr['generation_config']['top_k']);
        $this->assertTrue($arr['streaming_enabled']);
        $this->assertArrayNotHasKey('max_output_tokens', $arr['generation_config']);
        $this->assertArrayNotHasKey('stop_sequences', $arr['generation_config']);
    }

    public function testToArrayWithOptionalFields(): void
    {
        $preset = new SynapseModelPreset();
        $preset->setProviderName('openai')
            ->setModel('gpt-4o')
            ->setGenerationMaxOutputTokens(2048)
            ->setGenerationStopSequences(['<END>'])
            ->setProviderOptions(['thinkingBudget' => 1000]);

        $arr = $preset->toArray();

        $this->assertSame(2048, $arr['generation_config']['max_output_tokens']);
        $this->assertSame(['<END>'], $arr['generation_config']['stop_sequences']);
        $this->assertSame(1000, $arr['thinkingBudget']);
    }
}
