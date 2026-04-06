<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Contract\SynapseDebugLoggerInterface;
use ArnaudMoncondhuy\SynapseCore\Event\DebugLogSubscriber;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptCaptureEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseExchangeCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class DebugLogSubscriberTest extends TestCase
{
    private SynapseDebugLoggerInterface $debugLogger;
    private CacheInterface $cache;
    private DebugLogSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->debugLogger = $this->createMock(SynapseDebugLoggerInterface::class);
        $this->cache = $this->createStub(CacheInterface::class);
        $this->cache->method('get')->willReturnCallback(fn ($key, $callback) => $callback($this->createStub(ItemInterface::class)));
        $this->subscriber = new DebugLogSubscriber($this->debugLogger, $this->cache);
    }

    public function testCapturesSystemPromptFromCaptureEvent(): void
    {
        $config = SynapseRuntimeConfig::fromArray(['model' => 'test', 'provider' => 'test']);
        $event = new PromptCaptureEvent('msg', [], [
            'contents' => [
                ['role' => 'system', 'content' => 'Instruction système.'],
                ['role' => 'user', 'content' => 'msg'],
            ],
        ], $config);

        $this->debugLogger->expects($this->once())
            ->method('logExchange')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn ($data) => 'Instruction système.' === $data['system_prompt'])
            );

        $this->subscriber->onPrePrompt($event);

        // Trigger persistence via ExchangeCompletedEvent with debug=true
        $this->subscriber->onExchangeCompleted(new SynapseExchangeCompletedEvent(
            'dbg_test', 'test', 'test', new TokenUsage(5, 3), [], true, [], []
        ));
    }

    public function testDoesNotPersistWhenDebugModeOff(): void
    {
        $config = SynapseRuntimeConfig::fromArray(['model' => 'test', 'provider' => 'test']);
        $event = new PromptCaptureEvent('msg', [], ['contents' => []], $config);
        $this->subscriber->onPrePrompt($event);

        $this->debugLogger->expects($this->never())->method('logExchange');

        $this->subscriber->onExchangeCompleted(new SynapseExchangeCompletedEvent(
            'dbg_test', 'test', 'test', new TokenUsage(5, 3), [], false, [], []
        ));
    }

    public function testAccumulatesChunksAcrossTurns(): void
    {
        $config = SynapseRuntimeConfig::fromArray(['model' => 'test', 'provider' => 'test']);
        $event = new PromptCaptureEvent('msg', [], ['contents' => []], $config);
        $this->subscriber->onPrePrompt($event);

        $this->subscriber->onChunkReceived(new SynapseChunkReceivedEvent(['text' => 'Hello ', 'usage' => []], 0));
        $this->subscriber->onChunkReceived(new SynapseChunkReceivedEvent(['text' => 'world', 'usage' => []], 0));
        $this->subscriber->onChunkReceived(new SynapseChunkReceivedEvent(['text' => 'Tour 2.', 'usage' => []], 1));

        $this->debugLogger->expects($this->once())
            ->method('logExchange')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($data) {
                    return 'Hello world' === $data['turns'][0]['text']
                        && 'Tour 2.' === $data['turns'][1]['text'];
                })
            );

        $this->subscriber->onExchangeCompleted(new SynapseExchangeCompletedEvent(
            'dbg_test', 'test', 'test', new TokenUsage(10, 5), [], true, [], []
        ));
    }

    public function testPropagatesWorkflowRunIdFromAgentContextToMetadata(): void
    {
        // Phase 7 : vérifier que AgentContext::$workflowRunId est bien copié dans
        // $metadata passé au logger, pour atterrir dans synapse_debug_log.workflow_run_id.
        $config = SynapseRuntimeConfig::fromArray(['model' => 'test', 'provider' => 'test']);
        $this->subscriber->onPrePrompt(new PromptCaptureEvent('msg', [], ['contents' => []], $config));

        $context = AgentContext::root(userId: 'user-42', origin: 'workflow')
            ->withWorkflowRunId('wf-run-abc-123');

        $capturedMetadata = null;
        $this->debugLogger->expects($this->once())
            ->method('logExchange')
            ->willReturnCallback(function ($id, $metadata, $payload) use (&$capturedMetadata): void {
                $capturedMetadata = $metadata;
            });

        $this->subscriber->onExchangeCompleted(new SynapseExchangeCompletedEvent(
            'dbg_test', 'test', 'test', new TokenUsage(5, 3), [], true, [], [], $context
        ));

        $this->assertIsArray($capturedMetadata);
        $this->assertArrayHasKey('workflow_run_id', $capturedMetadata);
        $this->assertSame('wf-run-abc-123', $capturedMetadata['workflow_run_id']);
    }

    public function testWorkflowRunIdAbsentWhenAgentContextIsNull(): void
    {
        // Sanity check : si aucun agent context, la clé workflow_run_id ne doit pas
        // être posée (pas de fuite, pas de valeur fantôme).
        $config = SynapseRuntimeConfig::fromArray(['model' => 'test', 'provider' => 'test']);
        $this->subscriber->onPrePrompt(new PromptCaptureEvent('msg', [], ['contents' => []], $config));

        $capturedMetadata = null;
        $this->debugLogger->expects($this->once())
            ->method('logExchange')
            ->willReturnCallback(function ($id, $metadata, $payload) use (&$capturedMetadata): void {
                $capturedMetadata = $metadata;
            });

        $this->subscriber->onExchangeCompleted(new SynapseExchangeCompletedEvent(
            'dbg_test', 'test', 'test', new TokenUsage(5, 3), [], true, [], [], null
        ));

        $this->assertIsArray($capturedMetadata);
        $this->assertArrayNotHasKey('workflow_run_id', $capturedMetadata);
    }
}
