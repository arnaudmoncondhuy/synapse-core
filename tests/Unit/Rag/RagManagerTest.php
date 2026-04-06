<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Rag;

use ArnaudMoncondhuy\SynapseCore\Rag\RagManager;
use ArnaudMoncondhuy\SynapseCore\Rag\RagSourceRegistry;
use ArnaudMoncondhuy\SynapseCore\Service\ChunkingService;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagSource;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagDocumentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class RagManagerTest extends TestCase
{
    public function testSearchReturnsEmptyWhenNoSlugs(): void
    {
        $manager = $this->buildManager();

        $this->assertSame([], $manager->search('query', []));
    }

    public function testSearchReturnsEmptyWhenSourceNotFound(): void
    {
        $sourceRepo = $this->createStub(SynapseRagSourceRepository::class);
        $sourceRepo->method('findBySlug')->willReturn(null);

        $manager = $this->buildManager(sourceRepo: $sourceRepo);

        $this->assertSame([], $manager->search('query', ['unknown']));
    }

    public function testClearDeletesDocumentsAndResetsCount(): void
    {
        $source = $this->createMock(SynapseRagSource::class);
        $source->expects($this->once())->method('setDocumentCount')->with(0);

        $sourceRepo = $this->createStub(SynapseRagSourceRepository::class);
        $sourceRepo->method('findBySlug')->willReturn($source);

        $docRepo = $this->createMock(SynapseRagDocumentRepository::class);
        $docRepo->expects($this->once())->method('deleteBySource')->with($source);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $manager = $this->buildManager(sourceRepo: $sourceRepo, docRepo: $docRepo, em: $em);
        $manager->clear('my-source');
    }

    public function testClearDoesNothingWhenSourceNotFound(): void
    {
        $sourceRepo = $this->createStub(SynapseRagSourceRepository::class);
        $sourceRepo->method('findBySlug')->willReturn(null);

        $docRepo = $this->createMock(SynapseRagDocumentRepository::class);
        $docRepo->expects($this->never())->method('deleteBySource');

        $manager = $this->buildManager(sourceRepo: $sourceRepo, docRepo: $docRepo);
        $manager->clear('nonexistent');
    }

    public function testReindexThrowsWhenNoProvider(): void
    {
        $registry = $this->createStub(RagSourceRegistry::class);
        $registry->method('get')->willReturn(null);

        $manager = $this->buildManager(registry: $registry);

        $this->expectException(\RuntimeException::class);
        $manager->reindex('unknown');
    }

    public function testIngestSkipsEmptyDocuments(): void
    {
        $source = $this->createStub(SynapseRagSource::class);

        $sourceRepo = $this->createStub(SynapseRagSourceRepository::class);
        $sourceRepo->method('findBySlug')->willReturn($source);

        $docRepo = $this->createStub(SynapseRagDocumentRepository::class);
        $docRepo->method('countBySource')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $manager = $this->buildManager(sourceRepo: $sourceRepo, docRepo: $docRepo, em: $em);
        $count = $manager->ingest('test', [['content' => '', 'sourceIdentifier' => '']]);

        $this->assertSame(0, $count);
    }

    private function buildManager(
        ?EmbeddingService $embeddingService = null,
        ?ChunkingService $chunkingService = null,
        ?SynapseRagSourceRepository $sourceRepo = null,
        ?SynapseRagDocumentRepository $docRepo = null,
        ?RagSourceRegistry $registry = null,
        ?EntityManagerInterface $em = null,
    ): RagManager {
        return new RagManager(
            $embeddingService ?? $this->createStub(EmbeddingService::class),
            $chunkingService ?? $this->createStub(ChunkingService::class),
            $sourceRepo ?? $this->createStub(SynapseRagSourceRepository::class),
            $docRepo ?? $this->createStub(SynapseRagDocumentRepository::class),
            $registry ?? $this->createStub(RagSourceRegistry::class),
            $em ?? $this->createStub(EntityManagerInterface::class),
        );
    }
}
