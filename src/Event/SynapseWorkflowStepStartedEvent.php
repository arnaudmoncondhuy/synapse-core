<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

/**
 * Dispatché par {@see \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\MultiAgent}
 * au début de l'exécution de chaque step de workflow.
 *
 * Permet au frontend (via NDJSON) d'afficher immédiatement qu'un step
 * est en cours de traitement dans la sidebar "réflexion interne".
 */
final class SynapseWorkflowStepStartedEvent
{
    public function __construct(
        public readonly string $workflowRunId,
        public readonly string $workflowKey,
        public readonly int $stepIndex,
        public readonly string $stepName,
        public readonly string $agentName,
        public readonly int $totalSteps,
    ) {
    }
}
