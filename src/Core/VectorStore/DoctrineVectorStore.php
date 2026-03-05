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

        $whereClauses = [];
        $params = ['vector' => $vectorString, 'limit' => $limit];

        // Règle de sécurité fondamentale : toujours filtrer par user_id
        if (!empty($filters['user_id'])) {
            $whereClauses[] = 'user_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }

        // Stratégie de scope :
        // Soit un scope spécifique est demandé (ex: pour l'API manual)
        // Soit on est en recherche contextuelle pour le LLM (sans scope spécifié) :
        // -> On veut les souvenirs 'user' (globaux) + les souvenirs 'conversation' uniquement s'ils lient à la conversation courante
        if (!empty($filters['scope'])) {
            $whereClauses[] = 'scope = :scope';
            $params['scope'] = $filters['scope'];
            if (!empty($filters['conversation_id'])) {
                $whereClauses[] = 'conversation_id = :conversation_id';
                $params['conversation_id'] = $filters['conversation_id'];
            }
        } else {
            // Lors du recall LLM normal
            $conversationId = $filters['conversation_id'] ?? null;
            if ($conversationId) {
                // (scope = 'user') OR (scope = 'conversation' AND conversation_id = 'X')
                $whereClauses[] = "(scope = 'user' OR (scope = 'conversation' AND conversation_id = :conversation_id))";
                $params['conversation_id'] = $conversationId;
            } else {
                // Sans conversation contextuelle, on ne remonte QUE les globaux
                $whereClauses[] = "scope = 'user'";
            }
        }

        $where = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $sql = "SELECT payload, (1 - (embedding::text::vector <=> :vector::text::vector)) as score 
                FROM synapse_vector_memory 
                {$where}
                ORDER BY embedding::text::vector <=> :vector::text::vector 
                LIMIT :limit";

        $result = $this->em->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();

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
        // Appliquer les filtres restrictifs stricts d'abord
        $criteria = [];
        if (!empty($filters['user_id'])) {
            $criteria['userId'] = $filters['user_id'];
        }

        // Pour le fallback, comme le OR array/Doctrine est complexe sans QueryBuilder, 
        // on ramène un scope large et on filtre en PHP pour respecter la logique du VectorStore
        $all = $this->repository->findBy($criteria);

        $results = [];
        $conversationId = $filters['conversation_id'] ?? null;
        $requestedScope = $filters['scope'] ?? null;

        foreach ($all as $item) {

            // Logique de filtrage des Scopes en mémoire (PHP)
            if ($requestedScope !== null) {
                if ($item->getScope() !== $requestedScope) continue;
                if ($conversationId && $item->getConversationId() !== $conversationId) continue;
            } else {
                // Recall LLM normal : 'user' autorisé PARTOUT. 'conversation' autorisé UNIQUEMENT si match.
                if ($item->getScope() === 'conversation' && $item->getConversationId() !== $conversationId) {
                    continue; // Skip les souvenirs des autres conversations
                }
                if ($item->getScope() !== 'user' && $item->getScope() !== 'conversation') {
                    continue; // Skip any unknown scope
                }
            }

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
