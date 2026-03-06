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
                'total_tokens' => 8
            ]
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
}
