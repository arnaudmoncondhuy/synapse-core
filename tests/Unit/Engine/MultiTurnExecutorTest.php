<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Engine;

use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChunkProcessor;
use ArnaudMoncondhuy\SynapseCore\Engine\MultiTurnExecutor;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolExecutor;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\StructuredOutputParseException;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ChunkProcessorResult;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\MultiTurnResult;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class MultiTurnExecutorTest extends TestCase
{
    private ChunkProcessor $chunkProcessor;
    private ToolExecutor $toolExecutor;
    private EventDispatcherInterface $dispatcher;
    private SynapseProfiler $profiler;
    private MultiTurnExecutor $executor;

    protected function setUp(): void
    {
        $this->chunkProcessor = $this->createMock(ChunkProcessor::class);
        $this->toolExecutor = $this->createMock(ToolExecutor::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->profiler = $this->createMock(SynapseProfiler::class);

        $this->executor = new MultiTurnExecutor(
            $this->chunkProcessor,
            $this->toolExecutor,
            $this->dispatcher,
            $this->profiler,
        );
    }

    private function buildClient(): LlmClientInterface
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->method('streamGenerateContent')->willReturnCallback(
            function () { yield []; }
        );

        return $client;
    }

    private function buildChunkResult(string $text = '', array $toolCalls = [], int $promptTokens = 5, int $completionTokens = 3): ChunkProcessorResult
    {
        return new ChunkProcessorResult($text, $toolCalls, new TokenUsage($promptTokens, $completionTokens), []);
    }

    public function testReturnsSingleTurnResult(): void
    {
        $this->chunkProcessor->method('process')
            ->willReturn($this->buildChunkResult('Bonjour !'));

        $prompt = ['contents' => [['role' => 'user', 'content' => 'Salut']]];
        $result = $this->executor->execute($prompt, $this->buildClient(), true, 5);

        $this->assertInstanceOf(MultiTurnResult::class, $result);
        $this->assertSame('Bonjour !', $result->fullText);
        $this->assertSame(8, $result->usage->totalTokens);
    }

    public function testAccumulatesTextAcrossMultipleTurns(): void
    {
        $toolCall = [['id' => 'c1', 'name' => 'my_tool', 'args' => []]];

        $this->chunkProcessor->method('process')
            ->willReturnOnConsecutiveCalls(
                $this->buildChunkResult('Turn1 ', $toolCall),
                $this->buildChunkResult('Turn2'),
            );

        $this->toolExecutor->method('execute')->willReturnCallback(
            function (array &$prompt) {
                $prompt['contents'][] = ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'result'];
            }
        );

        $prompt = ['contents' => [['role' => 'user', 'content' => 'question']]];
        $result = $this->executor->execute($prompt, $this->buildClient(), true, 5);

        $this->assertSame('Turn1 Turn2', $result->fullText);
    }

    public function testStopsAfterMaxTurns(): void
    {
        $toolCall = [['id' => 'c1', 'name' => 'tool', 'args' => []]];
        // Always returns tool calls → would loop forever without maxTurns.
        // After exhausting maxTurns, a final synthesis call (without tools) is made.
        $this->chunkProcessor->method('process')
            ->willReturnOnConsecutiveCalls(
                $this->buildChunkResult('x', $toolCall),
                $this->buildChunkResult('x', $toolCall),
                $this->buildChunkResult('x', $toolCall),
                $this->buildChunkResult(' (synthèse)'),  // safety-net call
            );

        $this->toolExecutor->method('execute')->willReturnCallback(
            function (array &$prompt) {
                $prompt['contents'][] = ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'ok'];
            }
        );

        $prompt = ['contents' => [['role' => 'user', 'content' => 'q']]];
        $result = $this->executor->execute($prompt, $this->buildClient(), true, 3);

        // 3 turns of tool calls ('x' each) + 1 synthesis call
        $this->assertSame('xxx (synthèse)', $result->fullText);
    }

    public function testUsageAccumulatedAcrossTurns(): void
    {
        $toolCall = [['id' => 'c1', 'name' => 'tool', 'args' => []]];

        $this->chunkProcessor->method('process')
            ->willReturnOnConsecutiveCalls(
                new ChunkProcessorResult('T1', $toolCall, new TokenUsage(10, 5), []),
                new ChunkProcessorResult('T2', [], new TokenUsage(8, 4), []),
            );

        $this->toolExecutor->method('execute')->willReturnCallback(
            function (array &$prompt) {
                $prompt['contents'][] = ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'ok'];
            }
        );

        $prompt = ['contents' => [['role' => 'user', 'content' => 'q']]];
        $result = $this->executor->execute($prompt, $this->buildClient(), true, 5);

        $this->assertSame(10 + 5 + 8 + 4, $result->usage->totalTokens);
    }

    public function testForwardsOptionsToClient(): void
    {
        // JSON valide pour ne pas déclencher le parser en mode structured output.
        $this->chunkProcessor->method('process')
            ->willReturn($this->buildChunkResult('{}'));

        $client = $this->createMock(LlmClientInterface::class);
        $capturedOptions = null;
        $client->expects($this->once())
            ->method('generateContent')
            ->willReturnCallback(function (array $contents, array $tools, ?string $model, array $options) use (&$capturedOptions): array {
                $capturedOptions = $options;

                return [];
            });

        $options = [
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'x', 'schema' => [], 'strict' => true]],
        ];

        $prompt = ['contents' => [['role' => 'user', 'content' => 'q']]];
        $this->executor->execute($prompt, $client, false, 1, $options);

        $this->assertSame($options, $capturedOptions);
    }

    public function testParsesStructuredOutputOnFinalTurn(): void
    {
        $jsonText = '{"city":"Lyon","temp":22.5}';

        $this->chunkProcessor->method('process')
            ->willReturn($this->buildChunkResult($jsonText));

        $options = [
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'x', 'schema' => [], 'strict' => true]],
        ];

        $prompt = ['contents' => [['role' => 'user', 'content' => 'q']]];
        $result = $this->executor->execute($prompt, $this->buildClient(), true, 1, $options);

        $this->assertSame(['city' => 'Lyon', 'temp' => 22.5], $result->structuredData);
    }

    public function testDoesNotParseStructuredOutputWhenResponseFormatAbsent(): void
    {
        // Même un texte ressemblant à du JSON ne doit pas être parsé sans response_format.
        $this->chunkProcessor->method('process')
            ->willReturn($this->buildChunkResult('{"a":1}'));

        $prompt = ['contents' => [['role' => 'user', 'content' => 'q']]];
        $result = $this->executor->execute($prompt, $this->buildClient(), true, 1);

        $this->assertNull($result->structuredData);
    }

    public function testThrowsStructuredOutputParseExceptionOnInvalidJson(): void
    {
        $this->chunkProcessor->method('process')
            ->willReturn($this->buildChunkResult('not json'));

        $options = [
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'x', 'schema' => [], 'strict' => true]],
        ];

        $prompt = ['contents' => [['role' => 'user', 'content' => 'q']]];

        try {
            $this->executor->execute($prompt, $this->buildClient(), true, 1, $options);
            $this->fail('Expected StructuredOutputParseException was not thrown');
        } catch (StructuredOutputParseException $e) {
            $this->assertSame('not json', $e->getRawText());
        }
    }
}
