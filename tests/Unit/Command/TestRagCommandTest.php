<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Command;

use ArnaudMoncondhuy\SynapseCore\Command\TestRagCommand;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Rag\RagManager;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestRagCommandTest extends TestCase
{
    private function buildCommand(
        ?RagManager $ragManager = null,
        ?SynapseAgentRepository $agentRepo = null,
        ?ChatService $chatService = null,
    ): TestRagCommand {
        return new TestRagCommand(
            $ragManager ?? $this->createStub(RagManager::class),
            $agentRepo ?? $this->createStub(SynapseAgentRepository::class),
            $chatService ?? $this->createStub(ChatService::class),
        );
    }

    public function testNoSourcesReturnsFailure(): void
    {
        $tester = new CommandTester($this->buildCommand());
        $tester->execute(['query' => 'What is Synapse?']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Aucune source RAG', $tester->getDisplay());
    }

    public function testSearchWithDirectSource(): void
    {
        $ragManager = $this->createStub(RagManager::class);
        $ragManager->method('search')
            ->willReturn([
                ['content' => 'Synapse is an AI bundle', 'sourceSlug' => 'docs', 'score' => 0.89],
            ]);

        $tester = new CommandTester($this->buildCommand(ragManager: $ragManager));
        $tester->execute([
            'query' => 'What is Synapse?',
            '--source' => ['docs'],
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 chunk(s)', $display);
        $this->assertStringContainsString('docs', $display);
    }

    public function testSearchReturnsNoResults(): void
    {
        $ragManager = $this->createStub(RagManager::class);
        $ragManager->method('search')->willReturn([]);

        $tester = new CommandTester($this->buildCommand(ragManager: $ragManager));
        $tester->execute([
            'query' => 'Obscure question',
            '--source' => ['docs'],
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Aucun chunk', $tester->getDisplay());
    }

    public function testUnknownAgentReturnsFailure(): void
    {
        $agentRepo = $this->createStub(SynapseAgentRepository::class);
        $agentRepo->method('findByKey')->willReturn(null);

        $tester = new CommandTester($this->buildCommand(agentRepo: $agentRepo));
        $tester->execute([
            'query' => 'test',
            '--agent' => 'nonexistent',
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Agent introuvable', $tester->getDisplay());
    }
}
