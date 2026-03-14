<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Engine\ContextTruncationService;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Event\ContextTruncationSubscriber;
use ArnaudMoncondhuy\SynapseCore\Event\SynapsePrePromptEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use PHPUnit\Framework\TestCase;

class ContextTruncationSubscriberTest extends TestCase
{
    private ContextTruncationService $truncationService;
    private ModelCapabilityRegistry $capabilityRegistry;

    protected function setUp(): void
    {
        $this->truncationService = $this->createStub(ContextTruncationService::class);
        $this->truncationService->method('truncate')->willReturnArgument(0); // passthrough par défaut

        $this->capabilityRegistry = $this->createStub(ModelCapabilityRegistry::class);
        $this->capabilityRegistry->method('getCapabilities')->willReturn(
            new ModelCapabilities(model: 'gemini-flash', provider: 'gemini', contextWindow: 100_000)
        );
    }

    // -------------------------------------------------------------------------
    // Cas passants — troncature appliquée
    // -------------------------------------------------------------------------

    public function testTruncationServiceIsCalledWhenModelHasContextWindow(): void
    {
        $truncationService = $this->createMock(ContextTruncationService::class);
        $truncationService->expects($this->once())
            ->method('truncate')
            ->willReturnArgument(0);

        $event = $this->buildEvent('gemini-flash', [
            ['role' => 'system', 'content' => 'System'],
            ['role' => 'user', 'content' => 'Bonjour'],
        ]);

        (new ContextTruncationSubscriber($truncationService, $this->capabilityRegistry))
            ->onPrePrompt($event);
    }

    public function testTruncatedMessagesAreSetBackOnEvent(): void
    {
        $truncated = [
            ['role' => 'system', 'content' => 'System'],
            ['role' => 'user', 'content' => 'dernière question'],
        ];

        $truncationService = $this->createStub(ContextTruncationService::class);
        $truncationService->method('truncate')->willReturn($truncated);

        $event = $this->buildEvent('gemini-flash', [
            ['role' => 'system', 'content' => 'System'],
            ['role' => 'user', 'content' => 'vieux message'],
            ['role' => 'assistant', 'content' => 'vieille réponse'],
            ['role' => 'user', 'content' => 'dernière question'],
        ]);

        (new ContextTruncationSubscriber($truncationService, $this->capabilityRegistry))
            ->onPrePrompt($event);

        $this->assertSame($truncated, $event->getPrompt()['contents']);
    }

    public function testContextWindowPassedToTruncationService(): void
    {
        $capabilities = new ModelCapabilities(
            model: 'gemini-pro',
            provider: 'gemini',
            contextWindow: 32_000,
        );

        $capabilityRegistry = $this->createStub(ModelCapabilityRegistry::class);
        $capabilityRegistry->method('getCapabilities')->willReturn($capabilities);

        $truncationService = $this->createMock(ContextTruncationService::class);
        $truncationService->expects($this->once())
            ->method('truncate')
            ->with($this->anything(), 32_000)
            ->willReturnArgument(0);

        $event = $this->buildEvent('gemini-pro', [['role' => 'user', 'content' => 'test']]);

        (new ContextTruncationSubscriber($truncationService, $capabilityRegistry))
            ->onPrePrompt($event);
    }

    public function testUsesMaxInputTokensWhenDefined(): void
    {
        $capabilities = new ModelCapabilities(
            model: 'gemini-pro',
            provider: 'gemini',
            contextWindow: 100_000,
            maxInputTokens: 8_192, // maxInputTokens prioritaire
        );

        $capabilityRegistry = $this->createStub(ModelCapabilityRegistry::class);
        $capabilityRegistry->method('getCapabilities')->willReturn($capabilities);

        $truncationService = $this->createMock(ContextTruncationService::class);
        $truncationService->expects($this->once())
            ->method('truncate')
            ->with($this->anything(), 8_192) // doit utiliser maxInputTokens
            ->willReturnArgument(0);

        $event = $this->buildEvent('gemini-pro', [['role' => 'user', 'content' => 'test']]);

        (new ContextTruncationSubscriber($truncationService, $capabilityRegistry))
            ->onPrePrompt($event);
    }

    // -------------------------------------------------------------------------
    // Cas où la troncature est ignorée
    // -------------------------------------------------------------------------

    public function testSkipsWhenModelNotInConfig(): void
    {
        $event = $this->buildEvent('', [['role' => 'user', 'content' => 'test']]);

        $truncationService = $this->createMock(ContextTruncationService::class);
        $truncationService->expects($this->never())->method('truncate');

        (new ContextTruncationSubscriber($truncationService, $this->capabilityRegistry))
            ->onPrePrompt($event);
    }

    public function testSkipsWhenContextWindowIsNull(): void
    {
        $capabilities = new ModelCapabilities(
            model: 'unknown-model',
            provider: 'gemini',
            contextWindow: null,
        );

        $capabilityRegistry = $this->createStub(ModelCapabilityRegistry::class);
        $capabilityRegistry->method('getCapabilities')->willReturn($capabilities);

        $truncationService = $this->createMock(ContextTruncationService::class);
        $truncationService->expects($this->never())->method('truncate');

        $event = $this->buildEvent('unknown-model', [['role' => 'user', 'content' => 'test']]);

        (new ContextTruncationSubscriber($truncationService, $capabilityRegistry))
            ->onPrePrompt($event);
    }

    public function testSkipsWhenContentsEmpty(): void
    {
        $truncationService = $this->createMock(ContextTruncationService::class);
        $truncationService->expects($this->never())->method('truncate');

        $event = $this->buildEvent('gemini-flash', []);

        (new ContextTruncationSubscriber($truncationService, $this->capabilityRegistry))
            ->onPrePrompt($event);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /** @param array<int, array<string, mixed>> $contents */
    private function buildEvent(string $model, array $contents): SynapsePrePromptEvent
    {
        $event = new SynapsePrePromptEvent('message', []);
        $event->setPrompt(['contents' => $contents]);
        $event->setConfig(['model' => $model]);

        return $event;
    }
}
