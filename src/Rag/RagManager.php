<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Rag;

use ArnaudMoncondhuy\SynapseCore\Service\ChunkingService;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagDocument;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagSource;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagDocumentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service principal d'orchestration RAG.
 *
 * Gère l'ingestion (chunking + embedding + stockage), la recherche sémantique
 * et la réindexation des sources déclarées par l'application hôte.
 */
class RagManager
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private EmbeddingService $embeddingService,
        private ChunkingService $chunkingService,
        private SynapseRagSourceRepository $sourceRepository,
        private SynapseRagDocumentRepository $documentRepository,
        private RagSourceRegistry $registry,
        private EntityManagerInterface $em,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Ingère des documents bruts dans une source RAG.
     *
     * Chaque document est découpé en chunks, vectorisé et persisté.
     * La source est auto-créée si elle n'existe pas encore.
     *
     * @param string $slug identifiant de la source
     * @param iterable<array<string, mixed>> $documents documents avec 'content' et 'sourceIdentifier'
     *
     * @return int nombre de chunks créés
     */
    public function ingest(string $slug, iterable $documents): int
    {
        $source = $this->resolveOrCreateSource($slug);
        $totalChunks = 0;
        $batchCount = 0;

        foreach ($documents as $document) {
            $content = $document['content'] ?? '';
            $sourceIdentifier = $document['sourceIdentifier'] ?? '';
            $metadata = $document['metadata'] ?? null;

            if ('' === $content || '' === $sourceIdentifier) {
                $this->logger?->warning('Synapse RAG: Document ignoré (content ou sourceIdentifier vide)', ['slug' => $slug]);
                continue;
            }

            // Supprimer les anciens chunks pour ce document (déduplication)
            $this->documentRepository->deleteBySourceIdentifier($source, $sourceIdentifier);

            // Découper en chunks
            $chunks = $this->chunkingService->chunkText($content);
            if (empty($chunks)) {
                continue;
            }

            // Générer les embeddings pour tous les chunks en batch
            $embeddings = $this->embeddingService->generateEmbeddings($chunks, purpose: 'rag_indexation');
            $vectors = $embeddings['embeddings'] ?? [];

            $chunkCount = count($chunks);
            foreach ($chunks as $i => $chunkText) {
                $vector = $vectors[$i] ?? [];
                if (empty($vector)) {
                    continue;
                }

                $doc = new SynapseRagDocument();
                $doc->setSource($source);
                $doc->setContent($chunkText);
                $doc->setEmbedding($vector);
                $doc->setMetadata($metadata);
                $doc->setChunkIndex($i);
                $doc->setTotalChunks($chunkCount);
                $doc->setSourceIdentifier($sourceIdentifier);

                $this->em->persist($doc);
                ++$totalChunks;
                ++$batchCount;

                if ($batchCount >= self::BATCH_SIZE) {
                    $this->em->flush();
                    $batchCount = 0;
                }
            }
        }

        // Flush final
        if ($batchCount > 0) {
            $this->em->flush();
        }

        // Mettre à jour les stats de la source
        $source->setDocumentCount($this->documentRepository->countBySource($source));
        $source->setLastIndexedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->logger?->info('Synapse RAG: Ingestion terminée', [
            'slug' => $slug,
            'chunks' => $totalChunks,
        ]);

        return $totalChunks;
    }

    /**
     * Supprime tous les documents d'une source.
     */
    public function clear(string $slug): void
    {
        $source = $this->sourceRepository->findBySlug($slug);
        if (!$source) {
            return;
        }

        $this->documentRepository->deleteBySource($source);
        $source->setDocumentCount(0);
        $this->em->flush();

        $this->logger?->info('Synapse RAG: Source vidée', ['slug' => $slug]);
    }

    /**
     * Recherche sémantique dans les sources assignées.
     *
     * @param string $query texte de la requête utilisateur
     * @param string[] $sourceSlugs slugs des sources à interroger
     * @param int $limit nombre max de résultats
     * @param float $minScore score minimum
     *
     * @return array<int, array{content: string, score: float, metadata: array<string, mixed>|null, sourceSlug: string, sourceIdentifier: string}>
     */
    public function search(string $query, array $sourceSlugs, int $limit = 5, float $minScore = 0.4): array
    {
        if (empty($sourceSlugs)) {
            return [];
        }

        // Résoudre les IDs des sources actives
        $sourceIds = [];
        foreach ($sourceSlugs as $slug) {
            $source = $this->sourceRepository->findBySlug($slug);
            if ($source && $source->isActive() && null !== $source->getId()) {
                $sourceIds[] = $source->getId();
            }
        }

        if (empty($sourceIds)) {
            return [];
        }

        // Vectoriser la requête
        $result = $this->embeddingService->generateEmbeddings($query, purpose: 'rag_search');
        if (empty($result['embeddings'])) {
            return [];
        }

        $vector = $result['embeddings'][0];

        return $this->documentRepository->searchSimilar($vector, $sourceIds, $limit, $minScore);
    }

    /**
     * Réindexe une source via son provider enregistré.
     *
     * @throws \RuntimeException si aucun provider n'est enregistré pour ce slug
     *
     * @return int nombre de chunks créés
     */
    public function reindex(string $slug): int
    {
        $provider = $this->registry->get($slug);
        if (!$provider) {
            throw new \RuntimeException(sprintf('Aucun RagSourceProvider enregistré pour le slug "%s".', $slug));
        }

        $this->clear($slug);

        return $this->ingest($slug, $provider->fetchDocuments());
    }

    /**
     * Résout ou crée la source pour un slug donné.
     */
    private function resolveOrCreateSource(string $slug): SynapseRagSource
    {
        $source = $this->sourceRepository->findBySlug($slug);
        if ($source) {
            return $source;
        }

        // Auto-créer la source à partir du provider ou avec des valeurs par défaut
        $source = new SynapseRagSource();
        $source->setSlug($slug);

        $provider = $this->registry->get($slug);
        if ($provider) {
            $source->setName($provider->getName());
            $source->setDescription($provider->getDescription());
        } else {
            $source->setName($slug);
        }

        $this->em->persist($source);
        $this->em->flush();

        return $source;
    }
}
