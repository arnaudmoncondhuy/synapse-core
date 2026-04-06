<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Command;

use ArnaudMoncondhuy\SynapseCore\Command\RagReindexCommand;
use ArnaudMoncondhuy\SynapseCore\Rag\RagManager;
use ArnaudMoncondhuy\SynapseCore\Rag\RagSourceRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RagReindexCommandTest extends TestCase
{
    private function buildCommand(
        ?RagManager $ragManager = null,
        ?RagSourceRegistry $registry = null,
        ?SynapseRagSourceRepository $sourceRepo = null,
    ): RagReindexCommand {
        return new RagReindexCommand(
            $ragManager ?? $this->createStub(RagManager::class),
            $registry ?? $this->createStub(RagSourceRegistry::class),
            $sourceRepo ?? $this->createStub(SynapseRagSourceRepository::class),
        );
    }

    public function testMissingSlugReturnsFailure(): void
    {
        $tester = new CommandTester($this->buildCommand());
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('slug de la source est requis', $tester->getDisplay());
    }

    public function testListSourcesWithEmptyRegistryAndDb(): void
    {
        $registry = $this->createStub(RagSourceRegistry::class);
        $registry->method('getAll')->willReturn([]);

        $sourceRepo = $this->createStub(SynapseRagSourceRepository::class);
        $sourceRepo->method('findAllOrdered')->willReturn([]);

        $tester = new CommandTester($this->buildCommand(registry: $registry, sourceRepo: $sourceRepo));
        $tester->execute(['--list' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Sources RAG', $tester->getDisplay());
        $this->assertStringContainsString('Aucun RagSourceProvider', $tester->getDisplay());
    }

    public function testReindexSuccess(): void
    {
        $registry = $this->createStub(RagSourceRegistry::class);
        $registry->method('has')->willReturn(true);

        $ragManager = $this->createStub(RagManager::class);
        $ragManager->method('reindex')->willReturn(42);

        $tester = new CommandTester($this->buildCommand(ragManager: $ragManager, registry: $registry));
        $tester->execute(['slug' => 'my-source']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('42 chunks', $tester->getDisplay());
    }

    public function testClearOnlySuccess(): void
    {
        $ragManager = $this->createMock(RagManager::class);
        $ragManager->expects($this->once())->method('clear')->with('my-source');

        $tester = new CommandTester($this->buildCommand(ragManager: $ragManager));
        $tester->execute(['slug' => 'my-source', '--clear-only' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('vid', $tester->getDisplay());
    }

    public function testReindexUnknownSlugReturnsFailure(): void
    {
        $registry = $this->createStub(RagSourceRegistry::class);
        $registry->method('has')->willReturn(false);

        $tester = new CommandTester($this->buildCommand(registry: $registry));
        $tester->execute(['slug' => 'unknown']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Aucun RagSourceProvider', $tester->getDisplay());
    }
}
