<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\VectorStore;

use ArnaudMoncondhuy\SynapseCore\Contract\VectorStoreInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseVectorMemory;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseVectorMemoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Implémentation Doctrine du VectorStore.
 * 
 * Supporte nativement PostgreSQL + pgvector pour des performances optimales.
 * Offre un fallback PHP pour les autres bases (MySQL, SQLite) avec un avertissement de performance.
 */
class DoctrineVectorStore implements VectorStoreInterface
{
    private bool $isPostgres;
    private bool $hasPgVector;

    public function __construct(
        private EntityManagerInterface $em,
        private SynapseVectorMemoryRepository $repository,
        private ?LoggerInterface $logger = null
    ) {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform()::class;
        $this->isPostgres = str_contains($platform, 'PostgreSQL');

        $this->hasPgVector = false;
        if ($this->isPostgres) {
            try {
                $ext = $connection->executeQuery("SELECT extversion FROM pg_extension WHERE extname = 'vector'")->fetchOne();
                $this->hasPgVector = (bool) $ext;
            } catch (\Exception) {
                $this->hasPgVector = false;
            }
        }
    }

    public function saveMemory(array $vector, array $payload): void
    {
        $memory = new SynapseVectorMemory();
        $memory->setEmbedding($vector);
        $memory->setPayload($payload);

        // Remplir les colonnes dénormalisées pour le filtrage et l'affichage
        if (isset($payload['content'])) {
            $memory->setContent($payload['content']);
        }
        if (isset($payload['user_id'])) {
            $memory->setUserId($payload['user_id']);
        }
        if (isset($payload['scope'])) {
            $memory->setScope($payload['scope']);
        }
        if (isset($payload['conversation_id'])) {
            $memory->setConversationId($payload['conversation_id']);
        }
        if (isset($payload['source_type'])) {
            $memory->setSourceType($payload['source_type']);
        }

        $this->em->persist($memory);
        $this->em->flush();
    }

    public function searchSimilar(array $vector, int $limit = 5, array $filters = []): array
    {
        if ($this->hasPgVector) {
            return $this->searchWithPgVector($vector, $limit, $filters);
        }

        // Fallback PHP (Avertissement)
        if ($this->logger) {
            $this->logger->warning('Synapse: Utilisation du fallback PHP pour le VectorStore. Les performances seront dégradées. Installez pgvector pour PostgreSQL.');
        }

        return $this->searchWithPhpFallback($vector, $limit, $filters);
    }

    /**
     * Recherche haute performance utilisant l'opérateur <=> (cosine distance) de pgvector.
     */
    private function searchWithPgVector(array $vector, int $limit, array $filters): array
    {
        $vectorString = '[' . implode(',', $vector) . ']';

        // Construction des filtres SQL — le user_id est imposé au niveau de la requête (Data Sealing)
        $whereClauses = [];
        $params = ['vector' => $vectorString, 'limit' => $limit];

        if (!empty($filters['user_id'])) {
            $whereClauses[] = 'user_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }
        if (!empty($filters['scope'])) {
            $whereClauses[] = 'scope = :scope';
            $params['scope'] = $filters['scope'];
        }
        if (!empty($filters['conversation_id'])) {
            $whereClauses[] = 'conversation_id = :conversation_id';
            $params['conversation_id'] = $filters['conversation_id'];
        }

        $where = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $sql = "SELECT payload, (1 - (embedding <=> :vector)) as score 
                FROM synapse_vector_memory 
                {$where}
                ORDER BY embedding <=> :vector 
                LIMIT :limit";

        $stmt = $this->em->getConnection()->prepare($sql);
        $result = $stmt->executeQuery($params)->fetchAllAssociative();

        return array_map(fn($row) => [
            'payload' => json_decode($row['payload'], true),
            'score' => (float) $row['score']
        ], $result);
    }

    /**
     * Recherche "Best-Effort" en PHP.
     */
    private function searchWithPhpFallback(array $vector, int $limit, array $filters): array
    {
        // Appliquer les filtres en base pour limiter le fetch (Data Sealing)
        $criteria = [];
        if (!empty($filters['user_id'])) {
            $criteria['userId'] = $filters['user_id'];
        }
        if (!empty($filters['scope'])) {
            $criteria['scope'] = $filters['scope'];
        }
        if (!empty($filters['conversation_id'])) {
            $criteria['conversationId'] = $filters['conversation_id'];
        }

        $all = empty($criteria) ? $this->repository->findAll() : $this->repository->findBy($criteria);

        $results = [];
        foreach ($all as $item) {
            $score = $this->calculateCosineSimilarity($vector, $item->getEmbedding());
            $results[] = [
                'payload' => $item->getPayload(),
                'score' => $score
            ];
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    private function calculateCosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        $count = min(count($vec1), count($vec2));
        for ($i = 0; $i < $count; $i++) {
            $v1 = $vec1[$i] ?? 0.0;
            $v2 = $vec2[$i] ?? 0.0;
            $dotProduct += $v1 * $v2;
            $norm1 += $v1 * $v1;
            $norm2 += $v2 * $v2;
        }

        if ($norm1 === 0.0 || $norm2 === 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($norm1) * sqrt($norm2));
    }
}
