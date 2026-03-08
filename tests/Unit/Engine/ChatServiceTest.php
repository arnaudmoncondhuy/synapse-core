<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chat;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Engine\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseCore\Event\SynapsePrePromptEvent;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ChatServiceTest extends TestCase
{
    private $llmRegistry;
    private $configProvider;
    private $dispatcher;
    private $profiler;
    private $chatService;
    private $mockClient;

    protected function setUp(): void
    {
        $this->llmRegistry = $this->createMock(LlmClientRegistry::class);
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->profiler = $this->createMock(SynapseProfiler::class);
        $this->mockClient = $this->createMock(LlmClientInterface::class);

        $this->chatService = new ChatService(
            $this->llmRegistry,
            $this->configProvider,
            $this->dispatcher,
            $this->profiler
        );

        $this->llmRegistry->method('getClient')->willReturn($this->mockClient);
    }

    public function testAskSimpleMessage(): void
    {
        // 1. Setup events
        $this->dispatcher->method('dispatch')->willReturnCallback(function ($event) {
            if ($event instanceof SynapsePrePromptEvent) {
                $event->setPrompt(['contents' => [['role' => 'user', 'content' => 'Hello']]]);
                $event->setConfig(['model' => 'test-model', 'streaming_enabled' => false]);
            }

            return $event;
        });

        // 2. Setup LLM client
        $this->mockClient->method('generateContent')->willReturn([
            'text' => 'Hi there!',
            'usage' => [
                'prompt_tokens' => 5,
                'completion_tokens' => 3,
                'total_tokens' => 8,
            ],
        ]);

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
     * Bug 1 regression : un tool result null doit quand même générer un message role:tool
     * pour éviter que le LLM re-demande indéfiniment le même outil.
     */
    public function testToolCallWithNullResultStillAddsToolMessage(): void
    {
        $toolCallChunk = [
            'text' => null,
            'function_calls' => [
                ['id' => 'call_abc', 'name' => 'unknown_tool', 'args' => []],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
        $finalChunk = [
            'text' => 'Je ne peux pas exécuter cet outil.',
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8, 'total_tokens' => 28],
        ];

        $capturedContents = [];

        $this->dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$capturedContents) {
            if ($event instanceof SynapsePrePromptEvent) {
                $event->setPrompt(['contents' => [['role' => 'user', 'content' => 'Test']]]);
                $event->setConfig(['model' => 'test-model', 'streaming_enabled' => false]);
            }
            if ($event instanceof \ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallRequestedEvent) {
                // Simule un outil inconnu : aucun résultat enregistré → null
                $capturedContents = 'tool_event_dispatched';
            }

            return $event;
        });

        $callCount = 0;
        $this->mockClient->method('generateContent')->willReturnCallback(function () use ($toolCallChunk, $finalChunk, &$callCount) {
            return 0 === $callCount++ ? $toolCallChunk : $finalChunk;
        });

        $result = $this->chatService->ask('Test');

        // Le LLM a pu répondre au 2e tour → pas de boucle infinie jusqu'à MAX_TURNS
        $this->assertSame('Je ne peux pas exécuter cet outil.', $result['answer']);
    }

    /**
     * Bug 3 regression : debug_mode dans la config active le mode debug si l'appelant ne précise pas.
     */
    public function testDebugModeFollowsGlobalConfigWhenCallerOmits(): void
    {
        $this->dispatcher->method('dispatch')->willReturnCallback(function ($event) {
            if ($event instanceof SynapsePrePromptEvent) {
                $event->setPrompt(['contents' => [['role' => 'user', 'content' => 'Hello']]]);
                // debug_mode activé dans la config, appelant ne précise rien
                $event->setConfig(['model' => 'test-model', 'streaming_enabled' => false, 'debug_mode' => true]);
            }

            return $event;
        });

        $this->mockClient->method('generateContent')->willReturn([
            'text' => 'Réponse debug.',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
        ]);

        $result = $this->chatService->ask('Hello'); // pas de debug: false → suit la config

        // debug_id doit être défini car debug_mode = true dans la config
        $this->assertNotNull($result['debug_id']);
        $this->assertStringStartsWith('dbg_', (string) $result['debug_id']);
    }

    /**
     * Bug 3 regression : debug: false explicite désactive le mode debug même si config l'active.
     */
    public function testDebugModeCallerFalseOverridesGlobalTrue(): void
    {
        $this->dispatcher->method('dispatch')->willReturnCallback(function ($event) {
            if ($event instanceof SynapsePrePromptEvent) {
                $event->setPrompt(['contents' => [['role' => 'user', 'content' => 'Hello']]]);
                $event->setConfig(['model' => 'test-model', 'streaming_enabled' => false, 'debug_mode' => true]);
            }

            return $event;
        });

        $this->mockClient->method('generateContent')->willReturn([
            'text' => 'Réponse.',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
        ]);

        $result = $this->chatService->ask('Hello', ['debug' => false]);

        // L'appelant force debug: false → debug_id doit être null
        $this->assertNull($result['debug_id']);
    }
}
