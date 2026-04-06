<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\ThinkingConfig;
use PHPUnit\Framework\TestCase;

class ThinkingConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new ThinkingConfig();

        $this->assertFalse($config->enabled);
        $this->assertSame(ThinkingConfig::DEFAULT_BUDGET, $config->budget);
        $this->assertSame('high', $config->reasoningEffort);
    }

    public function testFromArrayWithData(): void
    {
        $config = ThinkingConfig::fromArray([
            'enabled' => true,
            'budget' => 4096,
            'reasoning_effort' => 'low',
        ]);

        $this->assertTrue($config->enabled);
        $this->assertSame(4096, $config->budget);
        $this->assertSame('low', $config->reasoningEffort);
    }

    public function testFromArrayWithAliases(): void
    {
        $config = ThinkingConfig::fromArray([
            'thinking_budget' => 2048,
            'reasoningEffort' => 'medium',
        ]);

        $this->assertSame(2048, $config->budget);
        $this->assertSame('medium', $config->reasoningEffort);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $config = ThinkingConfig::fromArray([]);

        $this->assertFalse($config->enabled);
        $this->assertSame(ThinkingConfig::DEFAULT_BUDGET, $config->budget);
        $this->assertSame('high', $config->reasoningEffort);
    }
}
