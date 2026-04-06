<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;

/**
 * Agent-façade qui délègue son exécution à un workflow multi-agents.
 *
 * Du point de vue de l'appelant (chat UI, MCP, CLI), cet agent se comporte comme
 * n'importe quel autre agent : il expose un nom, une description, et un `call()`.
 * En interne, il passe la main à {@see WorkflowRunner::run()} qui orchestre
 * séquentiellement les sous-agents définis dans le workflow.
 *
 * Créé à la volée par {@see AgentResolver} quand l'entité {@see SynapseAgent}
 * résolvée possède un `workflowKey` non-null.
 */
final class WorkflowDelegatingAgent implements AgentInterface
{
    public function __construct(
        private readonly SynapseAgent $agent,
        private readonly SynapseWorkflow $workflow,
        private readonly WorkflowRunner $workflowRunner,
    ) {
    }

    public function getName(): string
    {
        return $this->agent->getKey();
    }

    public function getDescription(): string
    {
        return $this->agent->getDescription();
    }

    public function call(Input $input, array $options = []): Output
    {
        return $this->workflowRunner->run($this->workflow, $input, $options);
    }

    /**
     * Expose l'entité agent sous-jacente (métadonnées, accessControl, etc.).
     */
    public function getEntity(): SynapseAgent
    {
        return $this->agent;
    }

    /**
     * Expose le workflow sous-jacent (utile pour l'inspection/debug).
     */
    public function getWorkflow(): SynapseWorkflow
    {
        return $this->workflow;
    }
}
