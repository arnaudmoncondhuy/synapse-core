<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\PromptBuilder;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Event\ContextBuilderSubscriber;
use ArnaudMoncondhuy\SynapseCore\Event\SynapsePrePromptEvent;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use PHPUnit\Framework\TestCase;

class ContextBuilderSubscriberTest extends TestCase
{
    private PromptBuilder $promptBuilder;
    private ConfigProviderInterface $configProvider;
    private ToolRegistry $toolRegistry;
    private AgentRegistry $agentRegistry;
    private SynapseModelPresetRepository $presetRepo;
    private SynapseProfiler $profiler;

    protected function setUp(): void
    {
        $this->promptBuilder = $this->createStub(PromptBuilder::class);
        $this->promptBuilder->method('buildSystemMessage')->willReturn([
            'role' => 'system',
            'content' => 'System par défaut',
        ]);

        $this->configProvider = $this->createStub(ConfigProviderInterface::class);
        $this->configProvider->method('getConfig')->willReturn([
            'model' => 'gemini-flash',
            'provider' => 'gemini',
        ]);

        $this->toolRegistry = $this->createStub(ToolRegistry::class);
        $this->toolRegistry->method('getDefinitions')->willReturn([]);

        $this->agentRegistry = $this->createStub(AgentRegistry::class);
        $this->agentRegistry->method('get')->willReturn(null);

        $this->presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $this->presetRepo->method('findByKey')->willReturn(null);

        $this->profiler = $this->createStub(SynapseProfiler::class);
    }

    // -------------------------------------------------------------------------
    // Structure de base du prompt
    // -------------------------------------------------------------------------

    public function testPromptContainsSystemMessageAsFirstContent(): void
    {
        $event = new SynapsePrePromptEvent('Bonjour', []);

        $this->buildSubscriber()->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $this->assertSame('system', $contents[0]['role']);
        $this->assertSame('System par défaut', $contents[0]['content']);
    }

    public function testPromptContainsUserMessageAsLastContent(): void
    {
        $event = new SynapsePrePromptEvent('Ma question', []);

        $this->buildSubscriber()->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $last = end($contents);
        $this->assertSame('user', $last['role']);
        $this->assertSame('Ma question', $last['content']);
    }

    public function testPromptContainsToolDefinitions(): void
    {
        $toolRegistry = $this->createStub(ToolRegistry::class);
        $toolRegistry->method('getDefinitions')->willReturn([['name' => 'my_tool']]);

        $event = new SynapsePrePromptEvent('test', []);
        $this->buildSubscriber(toolRegistry: $toolRegistry)->onPrePrompt($event);

        $this->assertSame([['name' => 'my_tool']], $event->getPrompt()['toolDefinitions']);
    }

    public function testConfigIsSetOnEvent(): void
    {
        $event = new SynapsePrePromptEvent('test', []);
        $this->buildSubscriber()->onPrePrompt($event);

        $this->assertArrayHasKey('model', $event->getConfig());
    }

    // -------------------------------------------------------------------------
    // Override system_prompt développeur
    // -------------------------------------------------------------------------

    public function testSystemPromptOptionOverridesDefault(): void
    {
        $event = new SynapsePrePromptEvent('test', [
            'system_prompt' => 'Mon prompt custom',
        ]);

        $this->buildSubscriber()->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $this->assertSame('Mon prompt custom', $contents[0]['content']);
    }

    // -------------------------------------------------------------------------
    // Historique
    // -------------------------------------------------------------------------

    public function testHistoryIsIncludedBeforeUserMessage(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Premier message'],
            ['role' => 'assistant', 'content' => 'Première réponse'],
        ];

        $event = new SynapsePrePromptEvent('Nouveau message', ['history' => $history]);
        $this->buildSubscriber()->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        // system + 2 history + user courant = 4
        $this->assertCount(4, $contents);
        $this->assertSame('Premier message', $contents[1]['content']);
        $this->assertSame('Première réponse', $contents[2]['content']);
        $this->assertSame('Nouveau message', $contents[3]['content']);
    }

    public function testHistoryFiltersUnknownRoles(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'valide'],
            ['role' => 'system', 'content' => 'filtré — role system non accepté dans history'],
            ['role' => 'unknown', 'content' => 'filtré'],
        ];

        $event = new SynapsePrePromptEvent('question', ['history' => $history]);
        $this->buildSubscriber()->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        // system + 1 user history valide + user courant = 3
        $this->assertCount(3, $contents);
    }

    public function testHistoryPreservesAssistantToolCalls(): void
    {
        $toolCalls = [['id' => 'call_1', 'function' => ['name' => 'my_tool']]];
        $history = [
            ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls],
        ];

        $event = new SynapsePrePromptEvent('test', ['history' => $history]);
        $this->buildSubscriber()->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $assistantMsg = $contents[1];
        $this->assertSame($toolCalls, $assistantMsg['tool_calls']);
    }

    public function testHistoryIncludesToolMessages(): void
    {
        $history = [
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'résultat'],
        ];

        $event = new SynapsePrePromptEvent('test', ['history' => $history]);
        $this->buildSubscriber()->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $toolMsg = $contents[1];
        $this->assertSame('tool', $toolMsg['role']);
        $this->assertSame('call_1', $toolMsg['tool_call_id']);
    }

    // -------------------------------------------------------------------------
    // Vision (images)
    // -------------------------------------------------------------------------

    public function testImagesProduceMultipartUserMessage(): void
    {
        $images = [['mime_type' => 'image/png', 'data' => base64_encode('fake-png')]];

        $event = new SynapsePrePromptEvent('Décris cette image', [], [], [], $images);
        $this->buildSubscriber()->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $userMsg = end($contents);
        $this->assertIsArray($userMsg['content']);
        $this->assertSame('text', $userMsg['content'][0]['type']);
        $this->assertSame('image_url', $userMsg['content'][1]['type']);
    }

    // -------------------------------------------------------------------------
    // Outils désactivés par disabled_capabilities
    // -------------------------------------------------------------------------

    public function testFunctionCallingDisabledCapabilitySkipsTools(): void
    {
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getConfig')->willReturn([
            'model' => 'gemini-flash',
            'disabled_capabilities' => ['function_calling'],
        ]);

        $toolRegistry = $this->createMock(ToolRegistry::class);
        $toolRegistry->expects($this->never())->method('getDefinitions');

        $event = new SynapsePrePromptEvent('test', []);
        $this->buildSubscriber(configProvider: $configProvider, toolRegistry: $toolRegistry)->onPrePrompt($event);

        $this->assertSame([], $event->getPrompt()['toolDefinitions']);
    }

    // -------------------------------------------------------------------------
    // Tone dans les options
    // -------------------------------------------------------------------------

    public function testToneOptionIsPassedToPromptBuilder(): void
    {
        $promptBuilder = $this->createMock(PromptBuilder::class);
        $promptBuilder->expects($this->once())
            ->method('buildSystemMessage')
            ->with('formel')
            ->willReturn(['role' => 'system', 'content' => 'Ton formel']);

        $event = new SynapsePrePromptEvent('test', ['tone' => 'formel']);
        $this->buildSubscriber(promptBuilder: $promptBuilder)->onPrePrompt($event);

        $this->assertSame('formel', $event->getConfig()['active_tone']);
    }

    // -------------------------------------------------------------------------
    // Builder
    // -------------------------------------------------------------------------

    private function buildSubscriber(
        ?PromptBuilder $promptBuilder = null,
        ?ConfigProviderInterface $configProvider = null,
        ?ToolRegistry $toolRegistry = null,
    ): ContextBuilderSubscriber {
        return new ContextBuilderSubscriber(
            promptBuilder: $promptBuilder ?? $this->promptBuilder,
            configProvider: $configProvider ?? $this->configProvider,
            toolRegistry: $toolRegistry ?? $this->toolRegistry,
            agentRegistry: $this->agentRegistry,
            modelPresetRepository: $this->presetRepo,
            profiler: $this->profiler,
        );
    }
}
