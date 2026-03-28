<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagDocument;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Repository pour SynapseRagDocument avec recherche vectorielle intégrée.
 *
 * Supporte pgvector (PostgreSQL) pour les requêtes haute performance,
 * avec un fallback PHP pour les autres bases de données.
 *
 * @extends ServiceEntityRepository<SynapseRagDocument>
 */
class SynapseRagDocumentRepository extends ServiceEntityRepository
{
    private bool $hasPgVector;

    public function __construct(
        ManagerRegistry $registry,
        private ?LoggerInterface $logger = null,
    ) {
        parent::__construct($registry, SynapseRagDocument::class);

        $this->hasPgVector = false;
        try {
            $connection = $this->getEntityManager()->getConnection();
            $platform = $connection->getDatabasePlatform()::class;
            if (str_contains($platform, 'PostgreSQL')) {
                $ext = $connection->executeQuery("SELECT extversion FROM pg_extension WHERE extname = 'vector'")->fetchOne();
                $this->hasPgVector = (bool) $ext;
            }
        } catch (\Exception) {
            $this->hasPgVector = false;
        }
    }

    /**
     * @return SynapseRagDocument[]
     */
    public function findBySource(SynapseRagSource $source, int $limit = 50, int $offset = 0): array
    {
        /** @var SynapseRagDocument[] $result */
        $result = $this->createQueryBuilder('d')
            ->andWhere('d.source = :source')
            ->setParameter('source', $source)
            ->orderBy('d.sourceIdentifier', 'ASC')
            ->addOrderBy('d.chunkIndex', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countBySource(SynapseRagSource $source): int
    {
        /** @var int $count */
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.source = :source')
            ->setParameter('source', $source)
            ->getQuery()
            ->getSingleScalarResult();

        return $count;
    }

    /**
     * Compte tous les documents RAG en base.
     */
    public function countAll(): int
    {
        /** @var int $count */
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return $count;
    }

    /**
     * Supprime tous les documents d'une source (bulk DQL).
     */
    public function deleteBySource(SynapseRagSource $source): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.source = :source')
            ->setParameter('source', $source)
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime les documents d'une source par identifiant (pour déduplication lors du reindex).
     */
    public function deleteBySourceIdentifier(SynapseRagSource $source, string $sourceIdentifier): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.source = :source')
            ->andWhere('d.sourceIdentifier = :identifier')
            ->setParameter('source', $source)
            ->setParameter('identifier', $sourceIdentifier)
            ->getQuery()
            ->execute();
    }

    /**
     * Recherche les documents les plus similaires au vecteur donné, filtrés par sources.
     *
     * @param float[] $vector vecteur de la requête
     * @param int[] $sourceIds IDs des sources à interroger
     * @param int $limit nombre max de résultats
     * @param float $minScore score minimum de similarité
     *
     * @return array<int, array{content: string, score: float, metadata: array<string, mixed>|null, sourceSlug: string, sourceIdentifier: string}>
     */
    public function searchSimilar(array $vector, array $sourceIds, int $limit = 5, float $minScore = 0.4): array
    {
        if (empty($sourceIds)) {
            return [];
        }

        if ($this->hasPgVector) {
            return $this->searchWithPgVector($vector, $sourceIds, $limit, $minScore);
        }

        if ($this->logger) {
            $this->logger->warning('Synapse RAG: Utilisation du fallback PHP pour la recherche vectorielle. Installez pgvector pour de meilleures performances.');
        }

        return $this->searchWithPhpFallback($vector, $sourceIds, $limit, $minScore);
    }

    /**
     * @param float[] $vector
     * @param int[] $sourceIds
     *
     * @return array<int, array{content: string, score: float, metadata: array<string, mixed>|null, sourceSlug: string, sourceIdentifier: string}>
     */
    private function searchWithPgVector(array $vector, array $sourceIds, int $limit, float $minScore): array
    {
        $vectorString = '['.implode(',', $vector).']';

        $placeholders = [];
        $params = [
            'vector' => $vectorString,
            'limit' => $limit,
            'min_score' => $minScore,
        ];

        foreach ($sourceIds as $i => $id) {
            $key = 'source_'.$i;
            $placeholders[] = ':'.$key;
            $params[$key] = $id;
        }

        $inClause = implode(', ', $placeholders);

        $sql = <<<SQL
            SELECT d.content, d.metadata, d.source_identifier,
                   s.slug AS source_slug,
                   (1 - (d.embedding::text::vector <=> :vector::text::vector)) AS score
            FROM synapse_rag_document d
            INNER JOIN synapse_rag_source s ON s.id = d.source_id
            WHERE d.source_id IN ({$inClause})
              AND (1 - (d.embedding::text::vector <=> :vector::text::vector)) >= :min_score
            ORDER BY d.embedding::text::vector <=> :vector::text::vector
            LIMIT :limit
        SQL;

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(function (array $row): array {
            $metadata = is_string($row['metadata'] ?? null) ? json_decode($row['metadata'], true) : $row['metadata'];

            return [
                'content' => (string) $row['content'],
                'score' => (float) $row['score'],
                'metadata' => is_array($metadata) ? $metadata : null,
                'sourceSlug' => (string) $row['source_slug'],
                'sourceIdentifier' => (string) $row['source_identifier'],
            ];
        }, $rows);
    }

    /**
     * @param float[] $vector
     * @param int[] $sourceIds
     *
     * @return array<int, array{content: string, score: float, metadata: array<string, mixed>|null, sourceSlug: string, sourceIdentifier: string}>
     */
    private function searchWithPhpFallback(array $vector, array $sourceIds, int $limit, float $minScore): array
    {
        $qb = $this->createQueryBuilder('d')
            ->innerJoin('d.source', 's')
            ->addSelect('s')
            ->andWhere('d.source IN (:sourceIds)')
            ->setParameter('sourceIds', $sourceIds);

        /** @var SynapseRagDocument[] $documents */
        $documents = $qb->getQuery()->getResult();

        $results = [];
        foreach ($documents as $doc) {
            $score = $this->calculateCosineSimilarity($vector, $doc->getEmbedding());
            if ($score < $minScore) {
                continue;
            }

            $results[] = [
                'content' => $doc->getContent(),
                'score' => $score,
                'metadata' => $doc->getMetadata(),
                'sourceSlug' => $doc->getSource()?->getSlug() ?? '',
                'sourceIdentifier' => $doc->getSourceIdentifier(),
            ];
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * @param float[] $vec1
     * @param float[] $vec2
     */
    private function calculateCosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        $count = min(count($vec1), count($vec2));
        for ($i = 0; $i < $count; ++$i) {
            $v1 = $vec1[$i] ?? 0.0;
            $v2 = $vec2[$i] ?? 0.0;
            $dotProduct += $v1 * $v2;
            $norm1 += $v1 * $v1;
            $norm2 += $v2 * $v2;
        }

        if (0.0 === $norm1 || 0.0 === $norm2) {
            return 0.0;
        }

        return $dotProduct / (sqrt($norm1) * sqrt($norm2));
    }
}
