<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Memory;

use ArnaudMoncondhuy\SynapseCore\Contract\VectorStoreInterface;
use ArnaudMoncondhuy\SynapseCore\Memory\MemoryManager;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MemoryScope;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseVectorMemory;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseVectorMemoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class MemoryManagerTest extends TestCase
{
    private EmbeddingService $embeddingService;
    private VectorStoreInterface $vectorStore;
    private SynapseVectorMemoryRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->embeddingService = $this->createStub(EmbeddingService::class);
        $this->embeddingService->method('generateEmbeddings')->willReturn([
            'embeddings' => [[0.1, 0.2, 0.3]],
        ]);

        $this->vectorStore = $this->createStub(VectorStoreInterface::class);
        $this->repository = $this->createStub(SynapseVectorMemoryRepository::class);
        $this->em = $this->createStub(EntityManagerInterface::class);
    }

    // -------------------------------------------------------------------------
    // remember()
    // -------------------------------------------------------------------------

    public function testRememberCallsSaveMemoryOnVectorStore(): void
    {
        $vectorStore = $this->createMock(VectorStoreInterface::class);
        $vectorStore->expects($this->once())
            ->method('saveMemory')
            ->with(
                [0.1, 0.2, 0.3],
                $this->arrayHasKey('content'),
            );

        $manager = $this->buildManager(vectorStore: $vectorStore);
        $manager->remember('Je préfère le café noir');
    }

    public function testRememberPayloadContainsExpectedFields(): void
    {
        $capturedPayload = null;
        $vectorStore = $this->createStub(VectorStoreInterface::class);
        $vectorStore->method('saveMemory')->willReturnCallback(
            function (array $vector, array $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;
            }
        );

        $manager = $this->buildManager(vectorStore: $vectorStore);
        $manager->remember('Un fait important', MemoryScope::USER, 'user-42', 'conv-1', 'preference');

        $this->assertSame('Un fait important', $capturedPayload['content']);
        $this->assertSame('user-42', $capturedPayload['user_id']);
        $this->assertSame('user', $capturedPayload['scope']);
        $this->assertSame('conv-1', $capturedPayload['conversation_id']);
        $this->assertSame('preference', $capturedPayload['source_type']);
    }

    public function testRememberDoesNothingWhenEmbeddingEmpty(): void
    {
        $embeddingService = $this->createStub(EmbeddingService::class);
        $embeddingService->method('generateEmbeddings')->willReturn(['embeddings' => []]);

        $vectorStore = $this->createMock(VectorStoreInterface::class);
        $vectorStore->expects($this->never())->method('saveMemory');

        $manager = $this->buildManager(embeddingService: $embeddingService, vectorStore: $vectorStore);
        $manager->remember('texte sans embedding');
    }

    // -------------------------------------------------------------------------
    // recall()
    // -------------------------------------------------------------------------

    public function testRecallReturnsFormattedResults(): void
    {
        $this->vectorStore->method('searchSimilar')->willReturn([
            ['payload' => ['content' => 'Je préfère le café'], 'score' => 0.95],
            ['payload' => ['content' => 'J\'ai un chien'], 'score' => 0.80],
        ]);

        $results = $this->buildManager()->recall('boissons');

        $this->assertCount(2, $results);
        $this->assertSame('Je préfère le café', $results[0]['content']);
        $this->assertSame(0.95, $results[0]['score']);
        $this->assertArrayHasKey('metadata', $results[0]);
    }

    public function testRecallReturnsEmptyWhenNoEmbedding(): void
    {
        $embeddingService = $this->createStub(EmbeddingService::class);
        $embeddingService->method('generateEmbeddings')->willReturn(['embeddings' => []]);

        $results = $this->buildManager(embeddingService: $embeddingService)->recall('requête');

        $this->assertSame([], $results);
    }

    public function testRecallPassesFiltersToVectorStore(): void
    {
        $vectorStore = $this->createMock(VectorStoreInterface::class);
        $vectorStore->expects($this->once())
            ->method('searchSimilar')
            ->with(
                [0.1, 0.2, 0.3],
                5,
                ['user_id' => 'user-1', 'conversation_id' => 'conv-2'],
            )
            ->willReturn([]);

        // Repository must report memories exist for the user, otherwise recall() short-circuits
        $repository = $this->createStub(SynapseVectorMemoryRepository::class);
        $repository->method('count')->willReturn(1);

        $this->buildManager(vectorStore: $vectorStore, repository: $repository)->recall('query', 'user-1', 'conv-2', 5);
    }

    public function testRecallWithoutFiltersPassesEmptyFilters(): void
    {
        $vectorStore = $this->createMock(VectorStoreInterface::class);
        $vectorStore->expects($this->once())
            ->method('searchSimilar')
            ->with([0.1, 0.2, 0.3], 3, [])
            ->willReturn([]);

        $this->buildManager(vectorStore: $vectorStore)->recall('query', null, null, 3);
    }

    // -------------------------------------------------------------------------
    // forget()
    // -------------------------------------------------------------------------

    public function testForgetDoesNothingWhenMemoryNotFound(): void
    {
        $this->repository->method('find')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');

        $this->buildManager(em: $em)->forget(99);
    }

    public function testForgetRemovesMemoryWhenOwnerMatches(): void
    {
        $memory = $this->buildMemory('user-1');
        $this->repository->method('find')->willReturn($memory);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($memory);
        $em->expects($this->once())->method('flush');

        $this->buildManager(em: $em)->forget(1, 'user-1');
    }

    public function testForgetThrowsWhenUserDoesNotOwnMemory(): void
    {
        $memory = $this->buildMemory('owner-A');
        $this->repository->method('find')->willReturn($memory);

        $this->expectException(AccessDeniedHttpException::class);
        $this->buildManager()->forget(1, 'owner-B');
    }

    public function testForgetWithoutUserIdBypassesOwnerCheck(): void
    {
        $memory = $this->buildMemory('owner-A');
        $this->repository->method('find')->willReturn($memory);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove');

        // Sans userId : pas de vérification ownership
        $this->buildManager(em: $em)->forget(1);
    }

    // -------------------------------------------------------------------------
    // update()
    // -------------------------------------------------------------------------

    public function testUpdateThrowsWhenUserDoesNotOwnMemory(): void
    {
        $memory = $this->buildMemory('owner-A');
        $this->repository->method('find')->willReturn($memory);

        $this->expectException(AccessDeniedHttpException::class);
        $this->buildManager()->update(1, 'nouveau texte', 'owner-B');
    }

    public function testUpdateDoesNothingWhenMemoryNotFound(): void
    {
        $this->repository->method('find')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $this->buildManager(em: $em)->update(99, 'texte');
    }

    public function testUpdateSetsNewContentAndFlushes(): void
    {
        $memory = $this->buildMemory('user-1');
        $this->repository->method('find')->willReturn($memory);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $this->buildManager(em: $em)->update(1, 'contenu mis à jour', 'user-1');

        $this->assertSame('contenu mis à jour', $memory->getContent());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildManager(
        ?EmbeddingService $embeddingService = null,
        ?VectorStoreInterface $vectorStore = null,
        ?EntityManagerInterface $em = null,
        ?SynapseVectorMemoryRepository $repository = null,
    ): MemoryManager {
        return new MemoryManager(
            embeddingService: $embeddingService ?? $this->embeddingService,
            vectorStore: $vectorStore ?? $this->vectorStore,
            repository: $repository ?? $this->repository,
            em: $em ?? $this->em,
        );
    }

    private function buildMemory(string $userId): SynapseVectorMemory
    {
        $memory = new SynapseVectorMemory();
        $memory->setUserId($userId);
        $memory->setContent('contenu original');
        $memory->setEmbedding([0.1, 0.2]);
        $memory->setPayload(['content' => 'contenu original']);

        return $memory;
    }
}
