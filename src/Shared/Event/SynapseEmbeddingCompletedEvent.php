<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement déclenché à la fin d'une génération d'embeddings.
 * Permet notamment au TokenAccountingService d'enregistrer la consommation (prompt_tokens).
 */
class SynapseEmbeddingCompletedEvent extends Event
{
    public const NAME = 'synapse.embedding.completed';

    public function __construct(
        private readonly string $model,
        private readonly string $provider,
        private readonly int $promptTokens,
        private readonly int $totalTokens
    ) {}

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
}
