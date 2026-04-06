<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\ChatOptions;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use PHPUnit\Framework\TestCase;

class ChatOptionsTest extends TestCase
{
    public function testDefaultConstructor(): void
    {
        $opts = new ChatOptions();

        $this->assertNull($opts->tone);
        $this->assertNull($opts->agent);
        $this->assertNull($opts->history);
        $this->assertFalse($opts->stateless);
        $this->assertNull($opts->debug);
        $this->assertNull($opts->streaming);
        $this->assertNull($opts->conversationId);
        $this->assertNull($opts->userId);
        $this->assertNull($opts->estimatedCostReference);
        $this->assertFalse($opts->resetConversation);
        $this->assertNull($opts->systemPromptOverride);
        $this->assertNull($opts->tools);
        $this->assertNull($opts->toolsOverride);
        $this->assertNull($opts->modelPreset);
        $this->assertNull($opts->preset);
    }

    public function testFromArrayWithFullData(): void
    {
        $opts = ChatOptions::fromArray([
            'tone' => 'formal',
            'agent' => 'support',
            'history' => [['role' => 'user', 'content' => 'hi']],
            'stateless' => true,
            'debug' => true,
            'streaming' => false,
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
            'estimated_cost_reference' => 0.05,
            'reset_conversation' => true,
            'system_prompt' => 'You are helpful.',
            'tools' => ['search', 'calc'],
            'tools_override' => ['search'],
            'model_preset' => 'gpt4-turbo',
        ]);

        $this->assertSame('formal', $opts->tone);
        $this->assertSame('support', $opts->agent);
        $this->assertCount(1, $opts->history);
        $this->assertTrue($opts->stateless);
        $this->assertTrue($opts->debug);
        $this->assertFalse($opts->streaming);
        $this->assertSame('conv-123', $opts->conversationId);
        $this->assertSame('user-456', $opts->userId);
        $this->assertSame(0.05, $opts->estimatedCostReference);
        $this->assertTrue($opts->resetConversation);
        $this->assertSame('You are helpful.', $opts->systemPromptOverride);
        $this->assertSame(['search', 'calc'], $opts->tools);
        $this->assertSame(['search'], $opts->toolsOverride);
        $this->assertSame('gpt4-turbo', $opts->modelPreset);
        $this->assertNull($opts->preset);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $opts = ChatOptions::fromArray([]);

        $this->assertNull($opts->tone);
        $this->assertFalse($opts->stateless);
        $this->assertNull($opts->debug);
        $this->assertFalse($opts->resetConversation);
    }

    public function testFromArrayPresetMustBeSynapseModelPreset(): void
    {
        $opts = ChatOptions::fromArray(['preset' => 'not-an-object']);
        $this->assertNull($opts->preset);

        $preset = $this->createStub(SynapseModelPreset::class);
        $opts = ChatOptions::fromArray(['preset' => $preset]);
        $this->assertSame($preset, $opts->preset);
    }

    public function testToArrayRoundtrip(): void
    {
        $input = [
            'tone' => 'casual',
            'agent' => null,
            'stateless' => true,
            'debug' => false,
            'streaming' => true,
            'conversation_id' => 'abc',
            'user_id' => null,
            'reset_conversation' => false,
            'model_preset' => 'fast',
        ];

        $opts = ChatOptions::fromArray($input);
        $array = $opts->toArray();

        $this->assertSame('casual', $array['tone']);
        $this->assertTrue($array['stateless']);
        $this->assertFalse($array['debug']);
        $this->assertTrue($array['streaming']);
        $this->assertSame('abc', $array['conversation_id']);
        $this->assertSame('fast', $array['model_preset']);
    }
}
