<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Governance\AgentArchitect;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentArchitect;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentArchitect
 */
final class AgentArchitectTest extends TestCase
{
    public function testGetNameReturnsArchitect(): void
    {
        $agent = $this->createAgent();
        $this->assertSame('architect', $agent->getName());
    }

    public function testGetDescriptionIsNotEmpty(): void
    {
        $agent = $this->createAgent();
        $this->assertNotEmpty($agent->getDescription());
    }

    public function testCallReturnsErrorWhenActionMissing(): void
    {
        $agent = $this->createAgent();

        $output = $agent->call(Input::ofStructured(['description' => 'test']));
        $data = $output->getData();

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('action', $data['error']);
    }

    public function testCallReturnsErrorWhenDescriptionEmpty(): void
    {
        $agent = $this->createAgent();

        $output = $agent->call(Input::ofStructured(['action' => 'create_agent', 'description' => '']));
        $data = $output->getData();

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Description', $data['error']);
    }

    public function testCallReturnsErrorWhenNoPresetAvailable(): void
    {
        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findOneBy')->willReturn(null);
        $presetRepo->method('findActive')->willThrowException(new \Exception('Aucun preset'));

        $agent = $this->createAgent(
            architectPresetKey: '',
            presetRepo: $presetRepo,
        );

        $output = $agent->call(Input::ofStructured([
            'action' => 'create_agent',
            'description' => 'Un agent de support',
        ]));
        $data = $output->getData();

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Preset', $data['error']);
    }

    public function testCallFallsBackToActivePresetWhenKeyEmpty(): void
    {
        $preset = new SynapseModelPreset();
        $preset->setName('Active');
        $preset->setKey('active');

        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findActive')->willReturn($preset);

        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn(['structured_output' => null, 'answer' => 'test']);

        $agent = $this->createAgent(
            architectPresetKey: '',
            presetRepo: $presetRepo,
            chatService: $chatService,
        );

        $output = $agent->call(Input::ofStructured([
            'action' => 'create_agent',
            'description' => 'Un agent de support',
        ]));
        $data = $output->getData();

        // Le LLM est appelé (avec le preset actif) — l'erreur vient du structured output manquant, pas du preset
        $this->assertArrayHasKey('error', $data);
        $this->assertStringNotContainsString('Preset', $data['error']);
    }

    public function testCallReturnsErrorOnInvalidAction(): void
    {
        $agent = $this->createAgentWithPreset();

        $output = $agent->call(Input::ofStructured([
            'action' => 'invalid_action',
            'description' => 'test',
        ]));
        $data = $output->getData();

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('inconnue', $data['error']);
    }

    public function testCallCreateAgentDelegatesLlmCall(): void
    {
        $proposal = [
            'key' => 'support_tech',
            'name' => 'Support Technique',
            'emoji' => '🔧',
            'description' => 'Agent de support technique.',
            'system_prompt' => 'Tu es un agent de support...',
            'reasoning' => 'Choix basé sur...',
        ];

        $chatService = $this->createMock(ChatService::class);
        $chatService->expects($this->once())
            ->method('ask')
            ->with(
                $this->stringContains('concevoir un agent'),
                $this->callback(function (array $options): bool {
                    return isset($options['response_format'])
                        && 'architect_create_agent' === $options['response_format']['json_schema']['name']
                        && true === $options['stateless']
                        && 'governance' === $options['module'];
                }),
            )
            ->willReturn([
                'answer' => json_encode($proposal),
                'structured_output' => $proposal,
                'debug_id' => 'dbg-123',
                'usage' => ['total_tokens' => 500],
            ]);

        $agent = $this->createAgentWithPreset(chatService: $chatService);

        $output = $agent->call(Input::ofStructured([
            'action' => 'create_agent',
            'description' => 'Un agent de support technique',
        ]));

        $data = $output->getData();
        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame('support_tech', $data['key']);
        $this->assertSame('create_agent', $data['_action']);
        $this->assertSame('dbg-123', $data['_debug_id']);
        $this->assertSame(500, $output->getUsage()['total_tokens']);
    }

    public function testCallImprovePromptIncludesCurrentPrompt(): void
    {
        $existingAgent = new SynapseAgent();
        $existingAgent->setKey('support');
        $existingAgent->setName('Support');
        $existingAgent->setDescription('Agent support');
        $existingAgent->setSystemPrompt('Tu es un agent de support basique.');

        $agentRepo = $this->createStub(SynapseAgentRepository::class);
        $agentRepo->method('findByKey')->willReturn($existingAgent);

        $chatService = $this->createMock(ChatService::class);
        $chatService->expects($this->once())
            ->method('ask')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('Prompt actuel'),
                    $this->stringContains('Tu es un agent de support basique.')
                ),
                $this->callback(function (array $options): bool {
                    return 'architect_improve_prompt' === $options['response_format']['json_schema']['name'];
                }),
            )
            ->willReturn([
                'answer' => '{}',
                'structured_output' => [
                    'new_system_prompt' => 'Tu es un agent de support avancé...',
                    'changes_summary' => 'Ajout de clarté',
                    'reasoning' => 'Le prompt manquait de structure.',
                ],
                'usage' => [],
            ]);

        $agent = $this->createAgentWithPreset(chatService: $chatService, agentRepo: $agentRepo);

        $output = $agent->call(Input::ofStructured([
            'action' => 'improve_prompt',
            'description' => 'Rendre plus clair',
            'agent_key' => 'support',
        ]));

        $data = $output->getData();
        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame('improve_prompt', $data['_action']);
        $this->assertSame('Tu es un agent de support avancé...', $data['new_system_prompt']);
    }

    public function testCallHandlesLlmException(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')
            ->willThrowException(new \RuntimeException('Quota exceeded'));

        $agent = $this->createAgentWithPreset(chatService: $chatService);

        $output = $agent->call(Input::ofStructured([
            'action' => 'create_agent',
            'description' => 'Un agent',
        ]));

        $data = $output->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Quota exceeded', $data['error']);
    }

    public function testCallReturnsErrorWhenNoStructuredOutput(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn([
            'answer' => 'text response without structured output',
            'usage' => [],
        ]);

        $agent = $this->createAgentWithPreset(chatService: $chatService);

        $output = $agent->call(Input::ofStructured([
            'action' => 'create_agent',
            'description' => 'Un agent',
        ]));

        $data = $output->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('structured output', $data['error']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createAgent(
        string $architectPresetKey = '',
        ?SynapseModelPresetRepository $presetRepo = null,
        ?ChatService $chatService = null,
    ): AgentArchitect {
        return new AgentArchitect(
            chatService: $chatService ?? $this->createStub(ChatService::class),
            presetRepository: $presetRepo ?? $this->createStub(SynapseModelPresetRepository::class),
            agentRepository: $this->createStub(SynapseAgentRepository::class),
            architectPresetKey: $architectPresetKey,
        );
    }

    private function createAgentWithPreset(
        ?ChatService $chatService = null,
        ?SynapseAgentRepository $agentRepo = null,
    ): AgentArchitect {
        $preset = $this->createStub(SynapseModelPreset::class);

        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findOneBy')->willReturn($preset);

        return new AgentArchitect(
            chatService: $chatService ?? $this->createStub(ChatService::class),
            presetRepository: $presetRepo,
            agentRepository: $agentRepo ?? $this->createStub(SynapseAgentRepository::class),
            architectPresetKey: 'architect_preset',
        );
    }
}
