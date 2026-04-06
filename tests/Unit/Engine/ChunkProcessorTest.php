<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Engine;

use ArnaudMoncondhuy\SynapseCore\Engine\ChunkProcessor;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseTokenStreamedEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ChunkProcessorTest extends TestCase
{
    private EventDispatcherInterface $dispatcher;
    private ChunkProcessor $processor;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->processor = new ChunkProcessor($this->dispatcher);
    }

    public function testAccumulatesTextAcrossChunks(): void
    {
        $chunks = [
            ['text' => 'Hello ', 'usage' => []],
            ['text' => 'world', 'usage' => []],
        ];

        $result = $this->processor->process($chunks, 0);

        $this->assertSame('Hello world', $result->modelText);
    }

    /**
     * Streaming providers (Gemini, OpenAI) send CUMULATIVE usage in each chunk.
     * The processor must keep the LAST usage, not accumulate.
     */
    public function testAccumulatesUsageAcrossChunks(): void
    {
        $chunks = [
            ['text' => 'a', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7]],
            ['text' => 'b', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8]],
        ];

        $result = $this->processor->process($chunks, 0);

        // Last chunk wins (cumulative usage from streaming providers)
        $this->assertSame(5, $result->usage->promptTokens);
        $this->assertSame(3, $result->usage->completionTokens);
    }

    public function testCollectsFunctionCalls(): void
    {
        $chunks = [
            [
                'text' => null,
                'function_calls' => [
                    ['id' => 'call_1', 'name' => 'my_tool', 'args' => ['key' => 'val']],
                ],
                'usage' => [],
            ],
        ];

        $result = $this->processor->process($chunks, 0);

        $this->assertCount(1, $result->modelToolCalls);
        $this->assertSame('my_tool', $result->modelToolCalls[0]['function']['name']);
        $this->assertSame('call_1', $result->modelToolCalls[0]['id']);
    }

    public function testBlockedChunkAddsMessageAndSkipsFunctionCalls(): void
    {
        $chunks = [
            [
                'text' => null,
                'blocked' => true,
                'blocked_reason' => 'harcèlement',
                'function_calls' => [['id' => 'call_1', 'name' => 'tool', 'args' => []]],
                'usage' => [],
            ],
        ];

        $result = $this->processor->process($chunks, 0);

        $this->assertStringContainsString('harcèlement', $result->modelText);
        $this->assertEmpty($result->modelToolCalls);
    }

    public function testDispatchesTokenStreamedEvent(): void
    {
        $this->dispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->with($this->callback(fn ($e) => $e instanceof SynapseTokenStreamedEvent || $e instanceof SynapseChunkReceivedEvent))
            ->willReturnArgument(0);

        $chunks = [['text' => 'bonjour', 'usage' => []]];
        $this->processor->process($chunks, 0);
    }

    public function testSkipsNonArrayChunks(): void
    {
        $chunks = ['invalid', null, 42, ['text' => 'valid', 'usage' => []]];

        $result = $this->processor->process($chunks, 0);

        $this->assertSame('valid', $result->modelText);
    }

    public function testLastSafetyRatingsWin(): void
    {
        $chunks = [
            ['text' => 'a', 'usage' => [], 'safety_ratings' => [['category' => 'hate', 'probability' => 'LOW']]],
            ['text' => 'b', 'usage' => [], 'safety_ratings' => [['category' => 'hate', 'probability' => 'HIGH']]],
        ];

        $result = $this->processor->process($chunks, 0);

        $this->assertSame('HIGH', $result->safetyRatings[0]['probability']);
    }
}
