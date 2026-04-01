<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chat;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Engine\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Engine\MultiTurnExecutor;
use ArnaudMoncondhuy\SynapseCore\Engine\PromptPipeline;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\MultiTurnResult;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ChatServiceTest extends TestCase
{
    private $llmRegistry;
    private $configProvider;
    private $dispatcher;
    private $profiler;
    private $multiTurnExecutor;
    private $promptPipeline;
    private $chatService;
    private $mockClient;

    protected function setUp(): void
    {
        $this->llmRegistry = $this->createMock(LlmClientRegistry::class);
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->profiler = $this->createMock(SynapseProfiler::class);
        $this->multiTurnExecutor = $this->createMock(MultiTurnExecutor::class);
        $this->promptPipeline = $this->createMock(PromptPipeline::class);
        $this->mockClient = $this->createMock(LlmClientInterface::class);

        $this->chatService = new ChatService(
            $this->llmRegistry,
            $this->configProvider,
            $this->dispatcher,
            $this->profiler,
            $this->multiTurnExecutor,
            $this->promptPipeline,
            $this->createMock(ModelCapabilityRegistry::class),
        );

        $this->llmRegistry->method('getClient')->willReturn($this->mockClient);
    }

    private function setupPipeline(string $model = 'test-model', bool $streaming = false, bool $debugMode = false): void
    {
        $config = SynapseRuntimeConfig::fromArray(['model' => $model, 'streaming_enabled' => $streaming, 'debug_mode' => $debugMode]);
        $this->promptPipeline->method('build')->willReturn([
            'prompt' => ['contents' => [['role' => 'user', 'content' => 'Hello']]],
            'config' => $config,
        ]);
    }

    public function testAskSimpleMessage(): void
    {
        $this->setupPipeline('test-model');
        $this->multiTurnExecutor->method('execute')->willReturn(
            new MultiTurnResult('Hi there!', new TokenUsage(5, 3), [], [])
        );

        $result = $this->chatService->ask('Hello');

        $this->assertSame('Hi there!', $result['answer']);
        $this->assertSame('test-model', $result['model']);
        $this->assertSame(8, $result['usage']['total_tokens']);
    }

    public function testAskEmptyWithReset(): void
    {
        $result = $this->chatService->ask('', ['reset_conversation' => true]);
        $this->assertSame('', $result['answer']);
    }

    /**
     * Bug 1 regression : ChatService retransmet correctement le résultat du MultiTurnExecutor
     * même dans un scénario multi-tours avec tool calls.
     * La logique null tool result est testée dans ToolExecutorTest.
     */
    public function testToolCallWithNullResultStillAddsToolMessage(): void
    {
        $this->setupPipeline('test-model');
        $this->multiTurnExecutor->method('execute')->willReturn(
            new MultiTurnResult('Je ne peux pas exécuter cet outil.', new TokenUsage(20, 8), [], [])
        );

        $result = $this->chatService->ask('Test');

        $this->assertSame('Je ne peux pas exécuter cet outil.', $result['answer']);
    }

    /**
     * Bug 3 regression : debug_mode dans la config active le mode debug si l'appelant ne précise pas.
     */
    public function testDebugModeFollowsGlobalConfigWhenCallerOmits(): void
    {
        $this->setupPipeline('test-model', false, true); // debug_mode = true
        $this->multiTurnExecutor->method('execute')->willReturn(
            new MultiTurnResult('Réponse debug.', new TokenUsage(5, 3), [], [])
        );

        $result = $this->chatService->ask('Hello'); // pas de debug: false → suit la config

        $this->assertNotNull($result['debug_id']);
        $this->assertStringStartsWith('dbg_', (string) $result['debug_id']);
    }

    /**
     * Bug 3 regression : debug: false explicite désactive le mode debug même si config l'active.
     */
    public function testDebugModeCallerFalseOverridesGlobalTrue(): void
    {
        $this->setupPipeline('test-model', false, true); // debug_mode = true dans config
        $this->multiTurnExecutor->method('execute')->willReturn(
            new MultiTurnResult('Réponse.', new TokenUsage(5, 3), [], [])
        );

        $result = $this->chatService->ask('Hello', ['debug' => false]);

        // L'appelant force debug: false → debug_id doit être null
        $this->assertNull($result['debug_id']);
    }
}
