<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Accounting;

use ArnaudMoncondhuy\SynapseCore\Shared\Event\SynapseEmbeddingCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Listener qui enregistre la consommation de tokens lors des générations d'embeddings.
 *
 * Branché sur SynapseEmbeddingCompletedEvent, émis par EmbeddingService
 * après chaque appel. Le purpose de l'event détermine le module et l'action loggés :
 *
 *  purpose              | module       | action
 *  ---------------------|--------------|------------------
 *  rag_indexation       | rag          | indexation
 *  rag_search           | conversation | rag_search
 *  memory_indexation    | memory       | indexation
 *  memory_search        | conversation | memory_search
 */
#[AsEventListener(event: SynapseEmbeddingCompletedEvent::NAME)]
final class EmbeddingUsageListener
{
    private const PURPOSE_MAP = [
        'rag_indexation' => ['module' => 'rag',          'action' => 'indexation'],
        'rag_search' => ['module' => 'conversation',  'action' => 'rag_search'],
        'memory_indexation' => ['module' => 'memory',        'action' => 'indexation'],
        'memory_search' => ['module' => 'conversation',  'action' => 'memory_search'],
    ];

    public function __construct(
        private readonly TokenAccountingService $accountingService,
    ) {
    }

    public function __invoke(SynapseEmbeddingCompletedEvent $event): void
    {
        $mapping = self::PURPOSE_MAP[$event->getPurpose()] ?? ['module' => 'rag', 'action' => 'indexation'];

        $this->accountingService->logUsage(
            module: $mapping['module'],
            action: $mapping['action'],
            model: $event->getModel(),
            usage: new TokenUsage(promptTokens: $event->getPromptTokens()),
        );
    }
}
