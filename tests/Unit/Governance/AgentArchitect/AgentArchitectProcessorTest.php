<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Governance\AgentArchitect;

use ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentArchitectProcessor;
use ArnaudMoncondhuy\SynapseCore\Governance\PromptVersionRecorder;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentPromptVersion;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentArchitectProcessor
 */
final class AgentArchitectProcessorTest extends TestCase
{
    // ── create_agent ──────────────────────────────────────────────────────

    public function testProcessCreateAgentCreatesInactiveAgent(): void
    {
        $agentRepo = $this->createStub(SynapseAgentRepository::class);
        $agentRepo->method('findByKey')->willReturn(null);

        $recorder = $this->createMock(PromptVersionRecorder::class);
        $recorder->expects($this->once())
            ->method('snapshot')
            ->with(
                $this->isInstanceOf(SynapseAgent::class),
                'Tu es un agent de support.',
                'agent:architect',
                'Choix basé sur la description.',
                false,
                true,
            );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')
            ->with($this->callback(fn (SynapseAgent $a): bool => 'support_tech' === $a->getKey()
                && 'Support Technique' === $a->getName()
                && false === $a->isActive()
                && false === $a->isBuiltin()
                && '🔧' === $a->getEmoji()
            ));
        $em->expects($this->once())->method('flush');

        $processor = new AgentArchitectProcessor($em, $agentRepo, $recorder);

        $result = $processor->process([
            '_action' => 'create_agent',
            'key' => 'support_tech',
            'name' => 'Support Technique',
            'emoji' => '🔧',
            'description' => 'Agent de support.',
            'system_prompt' => 'Tu es un agent de support.',
            'reasoning' => 'Choix basé sur la description.',
        ]);

        $this->assertSame('create_agent', $result['type']);
        $this->assertInstanceOf(SynapseAgent::class, $result['entity']);
        $this->assertStringContainsString('Support Technique', $result['message']);
    }

    public function testProcessCreateAgentThrowsOnDuplicateKey(): void
    {
        $existing = new SynapseAgent();
        $existing->setKey('support_tech');

        $agentRepo = $this->createMock(SynapseAgentRepository::class);
        $agentRepo->method('findByKey')->with('support_tech')->willReturn($existing);

        $processor = new AgentArchitectProcessor(
            $this->createStub(EntityManagerInterface::class),
            $agentRepo,
            $this->createStub(PromptVersionRecorder::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/existe déjà/');

        $processor->process([
            '_action' => 'create_agent',
            'key' => 'support_tech',
            'name' => 'Support',
            'system_prompt' => 'Tu es...',
        ]);
    }

    public function testProcessCreateAgentThrowsOnMissingKey(): void
    {
        $processor = new AgentArchitectProcessor(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SynapseAgentRepository::class),
            $this->createStub(PromptVersionRecorder::class),
        );

        $this->expectException(\InvalidArgumentException::class);

        $processor->process([
            '_action' => 'create_agent',
            'name' => 'Support',
            'system_prompt' => 'Tu es...',
        ]);
    }

    // ── improve_prompt ────────────────────────────────────────────────────

    public function testProcessImprovePromptCreatesSnapshotPending(): void
    {
        $agent = new SynapseAgent();
        $agent->setKey('support');
        $agent->setName('Support');

        $agentRepo = $this->createMock(SynapseAgentRepository::class);
        $agentRepo->method('findByKey')->with('support')->willReturn($agent);

        $version = new SynapseAgentPromptVersion();

        $recorder = $this->createMock(PromptVersionRecorder::class);
        $recorder->expects($this->once())
            ->method('snapshot')
            ->with(
                $agent,
                'Nouveau prompt amélioré.',
                'agent:architect',
                'Ajout de clarté et structure.',
                true,
                true,
            )
            ->willReturn($version);

        $processor = new AgentArchitectProcessor(
            $this->createStub(EntityManagerInterface::class),
            $agentRepo,
            $recorder,
        );

        $result = $processor->process([
            '_action' => 'improve_prompt',
            '_agent_key' => 'support',
            'new_system_prompt' => 'Nouveau prompt amélioré.',
            'changes_summary' => 'Ajout de clarté et structure.',
            'reasoning' => 'Le prompt manquait de structure.',
        ]);

        $this->assertSame('improve_prompt', $result['type']);
        $this->assertSame($agent, $result['entity']);
        $this->assertStringContainsString('Support', $result['message']);
    }

    public function testProcessImprovePromptThrowsOnMissingAgentKey(): void
    {
        $processor = new AgentArchitectProcessor(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SynapseAgentRepository::class),
            $this->createStub(PromptVersionRecorder::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/_agent_key/');

        $processor->process([
            '_action' => 'improve_prompt',
            'new_system_prompt' => 'test',
        ]);
    }

    public function testProcessImprovePromptThrowsOnAgentNotFound(): void
    {
        $agentRepo = $this->createStub(SynapseAgentRepository::class);
        $agentRepo->method('findByKey')->willReturn(null);

        $processor = new AgentArchitectProcessor(
            $this->createStub(EntityManagerInterface::class),
            $agentRepo,
            $this->createStub(PromptVersionRecorder::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/introuvable/');

        $processor->process([
            '_action' => 'improve_prompt',
            '_agent_key' => 'nonexistent',
            'new_system_prompt' => 'test',
        ]);
    }

    public function testProcessImprovePromptReportsNoChangeOnIdempotence(): void
    {
        $agent = new SynapseAgent();
        $agent->setKey('support');
        $agent->setName('Support');

        $agentRepo = $this->createStub(SynapseAgentRepository::class);
        $agentRepo->method('findByKey')->willReturn($agent);

        $recorder = $this->createStub(PromptVersionRecorder::class);
        $recorder->method('snapshot')->willReturn(null); // idempotence

        $processor = new AgentArchitectProcessor(
            $this->createStub(EntityManagerInterface::class),
            $agentRepo,
            $recorder,
        );

        $result = $processor->process([
            '_action' => 'improve_prompt',
            '_agent_key' => 'support',
            'new_system_prompt' => 'Same prompt',
            'changes_summary' => 'No change',
        ]);

        $this->assertStringContainsString('aucune modification', $result['message']);
    }

    // ── create_workflow ───────────────────────────────────────────────────

    public function testProcessCreateWorkflowCreatesInactiveWorkflow(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')
            ->with($this->callback(fn (SynapseWorkflow $w): bool => 'doc_analysis' === $w->getWorkflowKey()
                && 'Analyse de Document' === $w->getName()
                && false === $w->isActive()
                && false === $w->isBuiltin()
            ));
        $em->expects($this->once())->method('flush');

        $processor = new AgentArchitectProcessor(
            $em,
            $this->createStub(SynapseAgentRepository::class),
            $this->createStub(PromptVersionRecorder::class),
        );

        $result = $processor->process([
            '_action' => 'create_workflow',
            'key' => 'doc_analysis',
            'name' => 'Analyse de Document',
            'description' => 'Analyse un document puis le résume.',
            'steps' => [
                ['name' => 'analyze', 'agent_name' => 'analyzer'],
                ['name' => 'summarize', 'agent_name' => 'summarizer'],
            ],
            'reasoning' => 'Architecture en deux étapes.',
        ]);

        $this->assertSame('create_workflow', $result['type']);
        $this->assertInstanceOf(SynapseWorkflow::class, $result['entity']);
        $this->assertStringContainsString('Analyse de Document', $result['message']);
    }

    public function testProcessCreateWorkflowThrowsOnEmptySteps(): void
    {
        $processor = new AgentArchitectProcessor(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SynapseAgentRepository::class),
            $this->createStub(PromptVersionRecorder::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/étape/');

        $processor->process([
            '_action' => 'create_workflow',
            'key' => 'test',
            'name' => 'Test',
            'steps' => [],
        ]);
    }

    // ── Dispatch ──────────────────────────────────────────────────────────

    public function testProcessThrowsOnMissingAction(): void
    {
        $processor = new AgentArchitectProcessor(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SynapseAgentRepository::class),
            $this->createStub(PromptVersionRecorder::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/_action/');

        $processor->process(['key' => 'test']);
    }

    public function testProcessThrowsOnUnknownAction(): void
    {
        $processor = new AgentArchitectProcessor(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SynapseAgentRepository::class),
            $this->createStub(PromptVersionRecorder::class),
        );

        $this->expectException(\InvalidArgumentException::class);

        $processor->process(['_action' => 'do_magic']);
    }
}
