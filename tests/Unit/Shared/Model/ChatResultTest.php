<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\ChatResult;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use PHPUnit\Framework\TestCase;

class ChatResultTest extends TestCase
{
    public function testConstructorAndProperties(): void
    {
        $usage = new TokenUsage(100, 50);
        $result = new ChatResult(
            answer: 'Hello!',
            debugId: 'dbg-123',
            usage: $usage,
            safetyRatings: [['category' => 'HARM', 'probability' => 'LOW']],
            model: 'gpt-4',
            presetId: 42,
            agentId: 7,
        );

        $this->assertSame('Hello!', $result->answer);
        $this->assertSame('dbg-123', $result->debugId);
        $this->assertSame($usage, $result->usage);
        $this->assertSame('gpt-4', $result->model);
        $this->assertSame(42, $result->presetId);
        $this->assertSame(7, $result->agentId);
    }

    public function testToArray(): void
    {
        $usage = new TokenUsage(10, 20, 5);
        $result = new ChatResult(
            answer: 'Test',
            debugId: null,
            usage: $usage,
            safetyRatings: [],
            model: 'model-x',
            presetId: null,
            agentId: null,
        );

        $array = $result->toArray();

        $this->assertSame('Test', $array['answer']);
        $this->assertNull($array['debug_id']);
        $this->assertSame(35, $array['usage']['total_tokens']);
        $this->assertSame([], $array['safety']);
        $this->assertSame('model-x', $array['model']);
        $this->assertNull($array['preset_id']);
        $this->assertNull($array['agent_id']);
    }
}
