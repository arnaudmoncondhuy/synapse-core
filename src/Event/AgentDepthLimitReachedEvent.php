<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Déclenché lorsque la limite de profondeur d'imbrication agent-sur-agent
 * est atteinte, juste avant la levée de
 * {@see \ArnaudMoncondhuy\SynapseCore\Agent\Exception\AgentDepthExceededException}.
 *
 * Hook non bloquant : l'exception est levée de toute façon après le dispatch.
 * Permet à l'application hôte d'observer (alerte, notification, métrique).
 *
 * Implémente le garde-fou #5 documenté dans `.evolutions/CRITICAL_GUARDRAILS.md`.
 */
class AgentDepthLimitReachedEvent extends Event
{
    public function __construct(
        private readonly string $requestedAgentName,
        private readonly AgentContext $context,
    ) {
    }

    /**
     * Nom de l'agent qu'on a tenté de résoudre et qui a été refusé.
     */
    public function getRequestedAgentName(): string
    {
        return $this->requestedAgentName;
    }

    /**
     * Contexte d'exécution au moment du refus (contient depth, maxDepth, parentRunId, ...).
     */
    public function getContext(): AgentContext
    {
        return $this->context;
    }
}
