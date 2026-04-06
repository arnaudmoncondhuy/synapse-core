<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\GenerationConfig;
use PHPUnit\Framework\TestCase;

class GenerationConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new GenerationConfig();

        $this->assertSame(1.0, $config->temperature);
        $this->assertSame(0.95, $config->topP);
        $this->assertNull($config->topK);
        $this->assertNull($config->maxOutputTokens);
        $this->assertSame([], $config->stopSequences);
    }

    public function testFromArrayWithSnakeCaseKeys(): void
    {
        $config = GenerationConfig::fromArray([
            'temperature' => 0.7,
            'top_p' => 0.9,
            'top_k' => 40,
            'max_output_tokens' => 4096,
            'stop_sequences' => ['END', 'STOP'],
        ]);

        $this->assertSame(0.7, $config->temperature);
        $this->assertSame(0.9, $config->topP);
        $this->assertSame(40, $config->topK);
        $this->assertSame(4096, $config->maxOutputTokens);
        $this->assertSame(['END', 'STOP'], $config->stopSequences);
    }

    public function testFromArrayWithCamelCaseAliases(): void
    {
        $config = GenerationConfig::fromArray([
            'topP' => 0.8,
            'topK' => 50,
            'maxOutputTokens' => 2048,
            'stopSequences' => ['EOF'],
        ]);

        $this->assertSame(0.8, $config->topP);
        $this->assertSame(50, $config->topK);
        $this->assertSame(2048, $config->maxOutputTokens);
        $this->assertSame(['EOF'], $config->stopSequences);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $config = GenerationConfig::fromArray([]);

        $this->assertSame(1.0, $config->temperature);
        $this->assertSame(0.95, $config->topP);
        $this->assertNull($config->topK);
        $this->assertNull($config->maxOutputTokens);
        $this->assertSame([], $config->stopSequences);
    }

    public function testFromArrayNullTopKStaysNull(): void
    {
        $config = GenerationConfig::fromArray(['top_k' => null]);
        $this->assertNull($config->topK);
    }
}
