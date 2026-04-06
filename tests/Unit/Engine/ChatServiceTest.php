<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chat;

use ArnaudMoncondhuy\SynapseCore\Accounting\TokenAccountingService;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Engine\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Engine\MultiTurnExecutor;
use ArnaudMoncondhuy\SynapseCore\Engine\PromptPipeline;
use ArnaudMoncondhuy\SynapseCore\Engine\ResponseFormatNormalizer;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\ResponseSchemaNotSupportedException;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\MultiTurnResult;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseLlmCall;
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
    private $capabilityRegistry;
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
        $this->capabilityRegistry = $this->createMock(ModelCapabilityRegistry::class);
        $this->mockClient = $this->createMock(LlmClientInterface::class);

        $this->chatService = new ChatService(
            $this->llmRegistry,
            $this->configProvider,
            $this->dispatcher,
            $this->profiler,
            $this->multiTurnExecutor,
            $this->promptPipeline,
            $this->capabilityRegistry,
            new ResponseFormatNormalizer(),
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

    // =========================================================================
    // PHASE 6 — JSON Mode / Structured Output
    // =========================================================================

    public function testThrowsWhenResponseFormatOnUnsupportedModel(): void
    {
        $this->setupPipeline('unsupported-model');
        // Le modèle ne supporte ni 'response_schema' ni 'streaming' → le test ne dépend pas de l'ordre.
        $this->capabilityRegistry->method('supports')->willReturn(false);

        $this->expectException(ResponseSchemaNotSupportedException::class);

        $this->chatService->ask('Hello', [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'x', 'schema' => ['type' => 'object']],
            ],
        ]);
    }

    public function testForwardsNormalizedResponseFormatToExecutor(): void
    {
        $this->setupPipeline('gemini-2.5-flash');
        $this->capabilityRegistry->method('supports')->willReturnCallback(
            static fn (string $model, string $capability): bool => 'response_schema' === $capability,
        );

        $capturedOptions = null;
        $this->multiTurnExecutor
            ->method('execute')
            ->willReturnCallback(function ($prompt, $client, $streaming, $maxTurns, $options = []) use (&$capturedOptions): MultiTurnResult {
                $capturedOptions = $options;

                return new MultiTurnResult('{}', new TokenUsage(5, 3), [], [], [], ['ok' => true]);
            });

        $this->chatService->ask('Hello', [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'test', 'schema' => ['type' => 'object']],
            ],
        ]);

        $this->assertIsArray($capturedOptions);
        $this->assertArrayHasKey('response_format', $capturedOptions);
        $this->assertSame('json_schema', $capturedOptions['response_format']['type']);
        $this->assertSame('test', $capturedOptions['response_format']['json_schema']['name']);
        // Le normaliseur applique strict=true par défaut.
        $this->assertTrue($capturedOptions['response_format']['json_schema']['strict']);
    }

    public function testForcesStreamingOffWhenResponseFormatSet(): void
    {
        // Pipeline avec streaming=true mais response_format doit le forcer à off.
        $this->setupPipeline('gemini-2.5-flash', true);
        $this->capabilityRegistry->method('supports')->willReturnCallback(
            static fn (string $model, string $capability): bool => \in_array($capability, ['response_schema', 'streaming'], true),
        );

        $capturedStreaming = null;
        $this->multiTurnExecutor
            ->method('execute')
            ->willReturnCallback(function ($prompt, $client, $streaming, $maxTurns, $options = []) use (&$capturedStreaming): MultiTurnResult {
                $capturedStreaming = $streaming;

                return new MultiTurnResult('{}', new TokenUsage(5, 3), [], [], [], []);
            });

        $this->chatService->ask('Hello', [
            'streaming' => true,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'x', 'schema' => ['type' => 'object']],
            ],
        ]);

        $this->assertFalse($capturedStreaming, 'streaming doit être forcé à false quand response_format est actif');
    }

    public function testResultContainsStructuredOutput(): void
    {
        $this->setupPipeline('gemini-2.5-flash');
        $this->capabilityRegistry->method('supports')->willReturnCallback(
            static fn (string $model, string $capability): bool => 'response_schema' === $capability,
        );

        $this->multiTurnExecutor->method('execute')->willReturn(
            new MultiTurnResult('{"city":"Lyon"}', new TokenUsage(5, 3), [], [], [], ['city' => 'Lyon'])
        );

        $result = $this->chatService->ask('Hello', [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'x', 'schema' => ['type' => 'object']],
            ],
        ]);

        $this->assertArrayHasKey('structured_output', $result);
        $this->assertSame(['city' => 'Lyon'], $result['structured_output']);
    }

    public function testResultOmitsStructuredOutputWhenAbsent(): void
    {
        $this->setupPipeline('test-model');
        $this->multiTurnExecutor->method('execute')->willReturn(
            new MultiTurnResult('Hi there!', new TokenUsage(5, 3), [], [])
        );

        $result = $this->chatService->ask('Hello');

        $this->assertArrayNotHasKey('structured_output', $result);
    }

    // =========================================================================
    // Token accounting (source unique) — ChatService est le seul point d'entrée
    // pour TokenAccountingService::logUsage(). Voir feedback_token_cost_single_source.
    // =========================================================================

    public function testCallIdIsNullWhenAccountingServiceMissing(): void
    {
        $this->setupPipeline('test-model');
        $this->multiTurnExecutor->method('execute')->willReturn(
            new MultiTurnResult('Hi.', new TokenUsage(5, 3), [], [])
        );

        $result = $this->chatService->ask('Hello', ['module' => 'chat', 'action' => 'chat_turn']);

        $this->assertArrayHasKey('call_id', $result);
        $this->assertNull($result['call_id']);
    }

    public function testLogUsageCalledWhenModuleAndActionProvided(): void
    {
        $tokenAccounting = $this->createMock(TokenAccountingService::class);
        $llmCall = $this->createMock(SynapseLlmCall::class);
        $llmCall->method('getCallId')->willReturn('call-abc-123');

        $tokenAccounting
            ->expects($this->once())
            ->method('logUsage')
            ->with(
                'chat',
                'chat_turn',
                'test-model',
                $this->isInstanceOf(TokenUsage::class),
                'user-42',
                'conv-7',
                null,
                null,
            )
            ->willReturn($llmCall);

        $chatService = new ChatService(
            $this->llmRegistry,
            $this->configProvider,
            $this->dispatcher,
            $this->profiler,
            $this->multiTurnExecutor,
            $this->promptPipeline,
            $this->capabilityRegistry,
            new ResponseFormatNormalizer(),
            null,
            null,
            null,
            $tokenAccounting,
        );

        $this->setupPipeline('test-model');
        $this->multiTurnExecutor->method('execute')->willReturn(
            new MultiTurnResult('Hi.', new TokenUsage(5, 3), [], [])
        );

        $result = $chatService->ask('Hello', [
            'module' => 'chat',
            'action' => 'chat_turn',
            'user_id' => 'user-42',
            'conversation_id' => 'conv-7',
        ]);

        $this->assertSame('call-abc-123', $result['call_id']);
    }

    public function testLogUsageSkippedWhenModuleMissing(): void
    {
        $tokenAccounting = $this->createMock(TokenAccountingService::class);
        $tokenAccounting->expects($this->never())->method('logUsage');

        $chatService = new ChatService(
            $this->llmRegistry,
            $this->configProvider,
            $this->dispatcher,
            $this->profiler,
            $this->multiTurnExecutor,
            $this->promptPipeline,
            $this->capabilityRegistry,
            new ResponseFormatNormalizer(),
            null,
            null,
            null,
            $tokenAccounting,
        );

        $this->setupPipeline('test-model');
        $this->multiTurnExecutor->method('execute')->willReturn(
            new MultiTurnResult('Hi.', new TokenUsage(5, 3), [], [])
        );

        // Pas de `module` ni `action` → accounting skippé, mais l'appel doit aboutir.
        $result = $chatService->ask('Hello');

        $this->assertSame('Hi.', $result['answer']);
        $this->assertNull($result['call_id']);
    }

    public function testLogUsageFailureDoesNotBreakExchange(): void
    {
        $tokenAccounting = $this->createMock(TokenAccountingService::class);
        $tokenAccounting
            ->method('logUsage')
            ->willThrowException(new \RuntimeException('DB down'));

        $chatService = new ChatService(
            $this->llmRegistry,
            $this->configProvider,
            $this->dispatcher,
            $this->profiler,
            $this->multiTurnExecutor,
            $this->promptPipeline,
            $this->capabilityRegistry,
            new ResponseFormatNormalizer(),
            null,
            null,
            null,
            $tokenAccounting,
        );

        $this->setupPipeline('test-model');
        $this->multiTurnExecutor->method('execute')->willReturn(
            new MultiTurnResult('Hi.', new TokenUsage(5, 3), [], [])
        );

        // L'exception côté accounting doit être avalée : l'utilisateur reçoit sa réponse.
        $result = $chatService->ask('Hello', ['module' => 'chat', 'action' => 'chat_turn']);

        $this->assertSame('Hi.', $result['answer']);
        $this->assertNull($result['call_id']);
    }
}
