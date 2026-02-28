<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Memory;

use ArnaudMoncondhuy\SynapseCore\Contract\VectorStoreInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MemoryScope;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseVectorMemory;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseVectorMemoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Service de haut niveau pour la gestion de la mémoire sémantique.
 *
 * Orchestre la vectorisation via EmbeddingService et le stockage via VectorStoreInterface.
 */
class MemoryManager
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private VectorStoreInterface $vectorStore,
        private SynapseVectorMemoryRepository $repository,
        private EntityManagerInterface $em
    ) {}

    /**
     * Enregistre un fait ou un document dans la mémoire sémantique.
     */
    public function remember(
        string $text,
        MemoryScope $scope = MemoryScope::USER,
        ?string $userId = null,
        ?string $conversationId = null,
        string $sourceType = 'fact'
    ): void {
        $result = $this->embeddingService->generateEmbeddings($text);

        if (empty($result['embeddings'])) {
            return;
        }

        $vector = $result['embeddings'][0];

        $payload = [
            'content' => $text,
            'user_id' => $userId,
            'scope' => $scope->value,
            'conversation_id' => $conversationId,
            'source_type' => $sourceType,
        ];

        $this->vectorStore->saveMemory($vector, $payload);
    }

    /**
     * Recherche dans la mémoire sémantique le contenu le plus pertinent.
     *
     * @return array<int, array{content: string, score: float, metadata: array}>
     */
    public function recall(string $query, ?string $userId = null, int $limit = 5): array
    {
        $result = $this->embeddingService->generateEmbeddings($query);

        if (empty($result['embeddings'])) {
            return [];
        }

        $vector = $result['embeddings'][0];

        $filters = [];
        if ($userId) {
            $filters['user_id'] = $userId;
        }

        $memories = $this->vectorStore->searchSimilar($vector, $limit, $filters);

        return array_map(fn($m) => [
            'content' => $m['payload']['content'] ?? '',
            'score' => $m['score'],
            'metadata' => $m['payload']
        ], $memories);
    }

    /**
     * Supprime un souvenir spécifique.
     */
    public function forget(int $memoryId, ?string $userId = null): void
    {
        $memory = $this->repository->find($memoryId);

        if (!$memory) {
            return;
        }

        // Sécurité : vérifier que le souvenir appartient bien à l'utilisateur
        if ($userId && $memory->getUserId() !== $userId) {
            throw new AccessDeniedHttpException("Vous n'avez pas le droit de supprimer ce souvenir.");
        }

        $this->em->remove($memory);
        $this->em->flush();
    }

    /**
     * Liste les souvenirs d'un utilisateur.
     *
     * @return SynapseVectorMemory[]
     */
    public function listForUser(string $userId, int $page = 1, int $limit = 20): array
    {
        return $this->repository->findBy(
            ['userId' => $userId],
            ['createdAt' => 'DESC'],
            $limit,
            ($page - 1) * $limit
        );
    }

    /**
     * Met à jour un souvenir existant (correction sémantique).
     */
    public function update(int $memoryId, string $newText, ?string $userId = null): void
    {
        $memory = $this->repository->find($memoryId);

        if (!$memory) {
            return;
        }

        if ($userId && $memory->getUserId() !== $userId) {
            throw new AccessDeniedHttpException("Vous n'avez pas le droit de modifier ce souvenir.");
        }

        // On doit re-générer l'embedding pour le nouveau texte
        $result = $this->embeddingService->generateEmbeddings($newText);

        if (!empty($result['embeddings'])) {
            $memory->setEmbedding($result['embeddings'][0]);
        }

        $memory->setContent($newText);

        $payload = $memory->getPayload();
        $payload['content'] = $newText;
        $memory->setPayload($payload);

        $this->em->flush();
    }
}
