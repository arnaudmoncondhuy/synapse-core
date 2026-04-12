<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Engine\PromptBuilder;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolConfigService;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Event\ContextBuilderSubscriber;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptBuildEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use ArnaudMoncondhuy\SynapseCore\ToneRegistry;
use PHPUnit\Framework\TestCase;

class ContextBuilderSubscriberTest extends TestCase
{
    private PromptBuilder $promptBuilder;
    private ConfigProviderInterface $configProvider;
    private ToolRegistry $toolRegistry;
    private ToolConfigService $toolConfigService;
    private AgentRegistry $agentRegistry;
    private CodeAgentRegistry $codeAgentRegistry;
    private SynapseModelPresetRepository $presetRepo;
    private ToneRegistry $toneRegistry;
    private SynapseProfiler $profiler;

    protected function setUp(): void
    {
        $this->promptBuilder = $this->createStub(PromptBuilder::class);
        $this->promptBuilder->method('buildSystemMessage')->willReturn([
            'role' => 'system',
            'content' => 'System par défaut',
        ]);

        $this->configProvider = $this->createStub(ConfigProviderInterface::class);
        $this->configProvider->method('getConfig')->willReturn(
            SynapseRuntimeConfig::fromArray(['model' => 'gemini-flash', 'provider' => 'gemini'])
        );

        $this->toolRegistry = $this->createStub(ToolRegistry::class);
        $this->toolRegistry->method('getDefinitions')->willReturn([]);
        $this->toolRegistry->method('getTools')->willReturn([]);

        $this->toolConfigService = $this->createStub(ToolConfigService::class);
        $this->toolConfigService->method('filterToolNames')->willReturnCallback(fn (array $names): array => $names);
        $this->toolConfigService->method('getDefaultExposedToolNames')->willReturn([]);

        $this->agentRegistry = $this->createStub(AgentRegistry::class);
        $this->agentRegistry->method('get')->willReturn(null);

        $this->codeAgentRegistry = new CodeAgentRegistry([]);

        $this->presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $this->presetRepo->method('findByKey')->willReturn(null);

        $this->toneRegistry = $this->createStub(ToneRegistry::class);
        $this->toneRegistry->method('getSystemPrompt')->willReturn(null);

        $this->profiler = $this->createStub(SynapseProfiler::class);
    }

    // -------------------------------------------------------------------------
    // Structure de base du prompt
    // -------------------------------------------------------------------------

    public function testPromptContainsSystemMessageAsFirstContent(): void
    {
        $event = new PromptBuildEvent('Bonjour', []);

        $this->buildSubscriber()->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $this->assertSame('system', $contents[0]['role']);
        $this->assertSame('System par défaut', $contents[0]['content']);
    }

    public function testPromptContainsUserMessageAsLastContent(): void
    {
        $event = new PromptBuildEvent('Ma question', []);

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

        $event = new PromptBuildEvent('test', []);
        $this->buildSubscriber(toolRegistry: $toolRegistry)->onPrePrompt($event);

        $this->assertSame([['name' => 'my_tool']], $event->getPrompt()['toolDefinitions']);
    }

    public function testConfigIsSetOnEvent(): void
    {
        $event = new PromptBuildEvent('test', []);
        $this->buildSubscriber()->onPrePrompt($event);

        $this->assertNotNull($event->getConfig());
        $this->assertNotEmpty($event->getConfig()->model);
    }

    // -------------------------------------------------------------------------
    // Override system_prompt développeur
    // -------------------------------------------------------------------------

    public function testSystemPromptOptionOverridesDefault(): void
    {
        $event = new PromptBuildEvent('test', [
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

        $event = new PromptBuildEvent('Nouveau message', ['history' => $history]);
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

        $event = new PromptBuildEvent('question', ['history' => $history]);
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

        $event = new PromptBuildEvent('test', ['history' => $history]);
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

        $event = new PromptBuildEvent('test', ['history' => $history]);
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

        $visionCaps = new \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities(
            model: 'gemini-flash',
            provider: 'gemini',
            supportsVision: true,
        );
        $capabilityRegistry = $this->createMock(ModelCapabilityRegistry::class);
        $capabilityRegistry->method('getCapabilities')->willReturn($visionCaps);
        $capabilityRegistry->method('supports')->willReturn(true);

        $event = new PromptBuildEvent('Décris cette image', [], [], null, $images);
        $this->buildSubscriber(capabilityRegistry: $capabilityRegistry)->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $userMsg = end($contents);
        $this->assertIsArray($userMsg['content']);
        $this->assertSame('text', $userMsg['content'][0]['type']);
        $this->assertSame('image_url', $userMsg['content'][1]['type']);
    }

    public function testCsvAttachmentInjectedAsText(): void
    {
        $csvContent = "nom,age\nAlice,30\nBob,25";
        $attachments = [['mime_type' => 'text/csv', 'data' => base64_encode($csvContent), 'name' => 'eleves.csv']];

        $visionCaps = new \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities(
            model: 'gemini-flash', provider: 'gemini', supportsVision: true,
        );
        $capabilityRegistry = $this->createMock(ModelCapabilityRegistry::class);
        $capabilityRegistry->method('getCapabilities')->willReturn($visionCaps);
        $capabilityRegistry->method('supports')->willReturn(true);

        $event = new PromptBuildEvent('Analyse', [], [], null, $attachments);
        $this->buildSubscriber(capabilityRegistry: $capabilityRegistry)->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $userMsg = end($contents);
        $this->assertIsArray($userMsg['content']);
        // Le CSV doit être injecté comme type 'text', pas 'image_url'
        $csvPart = $userMsg['content'][1];
        $this->assertSame('text', $csvPart['type']);
        $this->assertStringContainsString('eleves.csv', $csvPart['text']);
        $this->assertStringContainsString('Alice,30', $csvPart['text']);
    }

    public function testLargeTextFileTruncated(): void
    {
        // Contenu > 100 KB
        $bigContent = str_repeat('x', 150 * 1024);
        $attachments = [['mime_type' => 'text/plain', 'data' => base64_encode($bigContent), 'name' => 'big.txt']];

        $event = new PromptBuildEvent('Lis ce fichier', [], [], null, $attachments);
        $this->buildSubscriber()->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $userMsg = end($contents);
        $this->assertIsArray($userMsg['content']);
        $textPart = $userMsg['content'][1];
        $this->assertSame('text', $textPart['type']);
        $this->assertStringContainsString('tronqué', $textPart['text']);
    }

    public function testTextAttachmentPassesWithoutVision(): void
    {
        $csvData = base64_encode("nom,age\nAlice,30");
        $attachments = [['mime_type' => 'text/csv', 'data' => $csvData, 'name' => 'eleves.csv']];

        // Modèle sans vision mais avec types texte (le comportement par défaut de ModelCapabilities)
        $noVisionCaps = new \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities(
            model: 'gpt-4',
            provider: 'openai',
            supportsVision: false,
        );
        $capabilityRegistry = $this->createMock(ModelCapabilityRegistry::class);
        $capabilityRegistry->method('getCapabilities')->willReturn($noVisionCaps);
        $capabilityRegistry->method('supports')->willReturn(true);

        $event = new PromptBuildEvent('Analyse ce CSV', [], [], null, $attachments);
        $this->buildSubscriber(capabilityRegistry: $capabilityRegistry)->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $userMsg = end($contents);
        // Le message user doit être multipart avec le CSV
        $this->assertIsArray($userMsg['content']);
        $this->assertSame('text', $userMsg['content'][0]['type']);
    }

    public function testImageAttachmentDroppedWithoutVision(): void
    {
        $imageData = base64_encode('fake-png');
        $attachments = [['mime_type' => 'image/png', 'data' => $imageData, 'name' => 'photo.png']];

        $noVisionCaps = new \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities(
            model: 'text-only-model',
            provider: 'openai',
            supportsVision: false,
        );
        $capabilityRegistry = $this->createMock(ModelCapabilityRegistry::class);
        $capabilityRegistry->method('getCapabilities')->willReturn($noVisionCaps);
        $capabilityRegistry->method('supports')->willReturn(true);

        $event = new PromptBuildEvent('Décris cette image', [], [], null, $attachments);
        $this->buildSubscriber(capabilityRegistry: $capabilityRegistry)->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $userMsg = end($contents);
        // Sans vision, image/png n'est pas dans acceptedMimes → message string simple, pas multipart
        $this->assertIsString($userMsg['content']);
    }

    // -------------------------------------------------------------------------
    // Outils désactivés par disabled_capabilities
    // -------------------------------------------------------------------------

    public function testFunctionCallingDisabledCapabilitySkipsTools(): void
    {
        $configProvider = $this->createStub(ConfigProviderInterface::class);
        $configProvider->method('getConfig')->willReturn(
            SynapseRuntimeConfig::fromArray(['model' => 'gemini-flash', 'provider' => 'gemini', 'disabled_capabilities' => ['function_calling']])
        );

        $toolRegistry = $this->createMock(ToolRegistry::class);
        $toolRegistry->expects($this->never())->method('getDefinitions');

        $event = new PromptBuildEvent('test', []);
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

        $event = new PromptBuildEvent('test', ['tone' => 'formel']);
        $this->buildSubscriber(promptBuilder: $promptBuilder)->onPrePrompt($event);

        $this->assertSame('formel', $event->getConfig()->activeTone);
    }

    // -------------------------------------------------------------------------
    // Builder
    // -------------------------------------------------------------------------

    private function buildSubscriber(
        ?PromptBuilder $promptBuilder = null,
        ?ConfigProviderInterface $configProvider = null,
        ?ToolRegistry $toolRegistry = null,
        ?ToolConfigService $toolConfigService = null,
        ?ModelCapabilityRegistry $capabilityRegistry = null,
    ): ContextBuilderSubscriber {
        return new ContextBuilderSubscriber(
            promptBuilder: $promptBuilder ?? $this->promptBuilder,
            configProvider: $configProvider ?? $this->configProvider,
            toolRegistry: $toolRegistry ?? $this->toolRegistry,
            toolConfigService: $toolConfigService ?? $this->toolConfigService,
            agentRegistry: $this->agentRegistry,
            codeAgentRegistry: $this->codeAgentRegistry,
            modelPresetRepository: $this->presetRepo,
            toneRegistry: $this->toneRegistry,
            profiler: $this->profiler,
            capabilityRegistry: $capabilityRegistry ?? $this->createMock(ModelCapabilityRegistry::class),
        );
    }
}
