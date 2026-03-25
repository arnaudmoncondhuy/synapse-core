<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Accounting;

use ArnaudMoncondhuy\SynapseCore\Shared\Event\SynapseEmbeddingCompletedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Listener qui enregistre la consommation de tokens lors des générations d'embeddings.
 *
 * Branché sur SynapseEmbeddingCompletedEvent, émis par EmbeddingService
 * après chaque appel (indexation RAG, recherche sémantique, etc.).
 */
#[AsEventListener(event: SynapseEmbeddingCompletedEvent::NAME)]
final class EmbeddingUsageListener
{
    public function __construct(
        private readonly TokenAccountingService $accountingService,
    ) {
    }

    public function __invoke(SynapseEmbeddingCompletedEvent $event): void
    {
        $this->accountingService->logUsage(
            module: 'rag',
            action: 'embedding',
            model: $event->getModel(),
            usage: [
                'prompt_tokens' => $event->getPromptTokens(),
                'completion_tokens' => 0, // Les embeddings n'ont pas de completion tokens
                'thinking_tokens' => 0,
            ],
        );
    }
}
