<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\MessageHandler;

use ArnaudMoncondhuy\SynapseCore\Message\ReindexRagSourceMessage;
use ArnaudMoncondhuy\SynapseCore\Rag\RagManager;
use ArnaudMoncondhuy\SynapseCore\Rag\RagSourceRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler asynchrone pour la réindexation d'une source RAG Synapse.
 *
 * Réutilise l'ancien totalFiles comme estimation initiale de progression
 * pour éviter une double traversée des sources.
 */
#[AsMessageHandler]
final class ReindexRagSourceHandler
{
    private const PROGRESS_FLUSH_EVERY = 2;

    public function __construct(
        private readonly SynapseRagSourceRepository $sourceRepository,
        private readonly RagManager $ragManager,
        private readonly RagSourceRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ReindexRagSourceMessage $message): void
    {
        $source = $this->sourceRepository->find($message->sourceId);

        if (!$source) {
            $this->logger?->warning('ReindexRagSourceHandler: source introuvable', [
                'id' => $message->sourceId,
            ]);

            return;
        }

        $provider = $this->registry->get($source->getSlug());
        if (!$provider) {
            $source->setIndexingStatus('error');
            $source->setLastError(sprintf('Aucun provider enregistré pour le slug "%s".', $source->getSlug()));
            $this->em->flush();

            return;
        }

        // Estimation initiale : réutilise le total de la dernière indexation
        $estimatedTotal = $source->getTotalFiles() > 0 ? $source->getTotalFiles() : 0;

        $source->setIndexingStatus('indexing');
        $source->setDocumentCount(0);
        $source->setProcessedFiles(0);
        $source->setTotalFiles($estimatedTotal);
        $source->setLastError(null);
        $this->em->flush();

        $this->ragManager->clear($source->getSlug());

        $totalChunks = 0;
        $processedFiles = 0;

        try {
            foreach ($provider->fetchDocuments() as $document) {
                $chunks = $this->ragManager->ingest($source->getSlug(), [$document]);
                $totalChunks += $chunks;
                ++$processedFiles;

                if (0 === $processedFiles % self::PROGRESS_FLUSH_EVERY) {
                    $source->setDocumentCount($totalChunks);
                    $source->setProcessedFiles($processedFiles);
                    if ($processedFiles > $source->getTotalFiles()) {
                        $source->setTotalFiles($processedFiles + 5);
                    }
                    $this->em->flush();
                }
            }

            $source->setIndexingStatus('ready');
            $source->setDocumentCount($totalChunks);
            $source->setProcessedFiles($processedFiles);
            $source->setTotalFiles($processedFiles);
            $source->setLastIndexedAt(new \DateTimeImmutable());

            $this->logger?->info('ReindexRagSourceHandler: réindexation terminée', [
                'slug' => $source->getSlug(),
                'chunks' => $totalChunks,
                'files' => $processedFiles,
            ]);
        } catch (\Throwable $e) {
            $source->setIndexingStatus('error');
            $source->setLastError($e->getMessage());

            $this->logger?->error('ReindexRagSourceHandler: erreur', [
                'slug' => $source->getSlug(),
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->em->flush();
        }
    }
}
