<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\NormalizedChunk;
use PHPUnit\Framework\TestCase;

class NormalizedChunkTest extends TestCase
{
    public function testDefaultConstructor(): void
    {
        $chunk = new NormalizedChunk();

        $this->assertNull($chunk->text);
        $this->assertNull($chunk->thinking);
        $this->assertSame([], $chunk->functionCalls);
        $this->assertSame([], $chunk->attachments);
        $this->assertSame(0, $chunk->usage->totalTokens);
        $this->assertFalse($chunk->blocked);
        $this->assertNull($chunk->blockedReason);
    }

    public function testFromArrayWithFullData(): void
    {
        $chunk = NormalizedChunk::fromArray([
            'text' => 'Hello',
            'thinking' => 'reasoning...',
            'function_calls' => [['name' => 'search', 'args' => ['q' => 'test']]],
            'attachments' => [['mime_type' => 'image/png', 'data' => 'base64']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
            'safety_ratings' => [['category' => 'HARM', 'probability' => 'LOW']],
            'blocked' => true,
            'blocked_reason' => 'harcèlement',
        ]);

        $this->assertSame('Hello', $chunk->text);
        $this->assertSame('reasoning...', $chunk->thinking);
        $this->assertCount(1, $chunk->functionCalls);
        $this->assertCount(1, $chunk->attachments);
        $this->assertSame(30, $chunk->usage->totalTokens);
        $this->assertTrue($chunk->blocked);
        $this->assertSame('harcèlement', $chunk->blockedReason);
    }

    public function testFromArrayImagesAliasForAttachments(): void
    {
        $chunk = NormalizedChunk::fromArray([
            'images' => [['mime_type' => 'image/jpeg', 'data' => 'abc']],
        ]);

        $this->assertCount(1, $chunk->attachments);
    }

    public function testHasToolCalls(): void
    {
        $empty = new NormalizedChunk();
        $this->assertFalse($empty->hasToolCalls());

        $withCalls = new NormalizedChunk(functionCalls: [['name' => 'fn', 'args' => []]]);
        $this->assertTrue($withCalls->hasToolCalls());
    }

    public function testIsBlocked(): void
    {
        $this->assertFalse((new NormalizedChunk())->isBlocked());
        $this->assertTrue((new NormalizedChunk(blocked: true))->isBlocked());
    }

    public function testToArrayRoundtrip(): void
    {
        $chunk = NormalizedChunk::fromArray([
            'text' => 'Hi',
            'blocked' => false,
            'usage' => ['prompt_tokens' => 5],
        ]);

        $array = $chunk->toArray();

        $this->assertSame('Hi', $array['text']);
        $this->assertFalse($array['blocked']);
        $this->assertSame(5, $array['usage']['prompt_tokens']);
    }
}
