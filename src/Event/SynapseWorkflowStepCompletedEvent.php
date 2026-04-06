<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

/**
 * Dispatché par {@see \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\MultiAgent}
 * après chaque step de workflow exécuté avec succès.
 *
 * Permet au frontend (via NDJSON) d'afficher la progression en temps réel
 * dans la sidebar "réflexion interne" du chat.
 */
final class SynapseWorkflowStepCompletedEvent
{
    /**
     * @param array<string, mixed> $usage Tokens consommés par ce step (prompt_tokens, completion_tokens, total_tokens)
     */
    public function __construct(
        public readonly string $workflowRunId,
        public readonly string $workflowKey,
        public readonly int $stepIndex,
        public readonly string $stepName,
        public readonly string $agentName,
        public readonly ?string $answer,
        public readonly array $usage,
        public readonly int $totalSteps,
    ) {
    }
}
