<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Message;

/**
 * Message déclenché pour lancer une réindexation RAG en arrière-plan.
 */
final class ReindexRagSourceMessage
{
    public function __construct(
        public readonly int $sourceId,
    ) {
    }
}
