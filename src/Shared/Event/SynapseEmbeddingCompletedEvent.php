<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement déclenché à la fin d'une génération d'embeddings.
 * Permet notamment au TokenAccountingService d'enregistrer la consommation (prompt_tokens).
 *
 * Le champ $purpose identifie le contexte d'appel pour différencier les usages dans les stats :
 *  - 'rag_indexation'    : indexation de documents RAG (RagManager::ingest)
 *  - 'rag_search'        : vectorisation d'une requête RAG (RagManager::search)
 *  - 'memory_indexation' : mémorisation d'un fait utilisateur (MemoryManager::remember/reindex)
 *  - 'memory_search'     : vectorisation d'un rappel mémoire (MemoryManager::recall)
 */
class SynapseEmbeddingCompletedEvent extends Event
{
    public const NAME = 'synapse.embedding.completed';

    public function __construct(
        private readonly string $model,
        private readonly string $provider,
        private readonly int $promptTokens,
        private readonly int $totalTokens,
        private readonly string $purpose = 'rag_indexation',
    ) {
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }
}
