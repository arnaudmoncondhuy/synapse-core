<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;

/**
 * Registry pour accéder aux agents configurés.
 *
 * Utilisé par :
 * - ContextBuilderSubscriber : résoudre un agent par clé
 * - SynapseTwigExtension : exposer synapse_get_agents()
 */
class AgentRegistry
{
    public function __construct(
        private SynapseAgentRepository $repository,
    ) {}

    /**
     * Récupère tous les agents actifs, indexés par clé.
     *
     * @return array<string, array<string, mixed>> Agents indexés par key, converties en tableau
     */
    public function getAll(): array
    {
        $agents = $this->repository->findAllActive();
        $result = [];

        foreach ($agents as $agent) {
            $result[$agent->getKey()] = $agent->toArray();
        }

        return $result;
    }

    /**
     * Récupère un agent par sa clé unique.
     */
    public function get(string $key): ?SynapseAgent
    {
        return $this->repository->findByKey($key);
    }
}
