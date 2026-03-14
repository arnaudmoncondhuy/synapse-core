<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;

/**
 * Registry pour accéder aux agents configurés.
 *
 * Utilisé par :
 * - ContextBuilderSubscriber : résoudre un agent par clé
 * - SynapseTwigExtension : exposer synapse_get_agents()
 *
 * Filtre automatiquement les agents en fonction des permissions de l'utilisateur connecté.
 */
class AgentRegistry
{
    public function __construct(
        private SynapseAgentRepository $repository,
        private PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /**
     * Récupère tous les agents actifs et accessibles par l'utilisateur connecté, indexés par clé.
     *
     * Filtre automatiquement selon les permissions définies dans `accessControl`.
     *
     * @return array<string, array<string, mixed>> Agents indexés par key, converties en tableau
     */
    public function getAll(): array
    {
        $agents = $this->repository->findAllActive();
        $result = [];

        foreach ($agents as $agent) {
            // Vérifier les permissions avant d'inclure l'agent
            if ($this->permissionChecker->canUseAgent($agent)) {
                $result[$agent->getKey()] = $agent->toArray();
            }
        }

        return $result;
    }

    /**
     * Récupère un agent par sa clé unique.
     *
     * Retourne null si l'agent n'existe pas OU si l'utilisateur n'a pas les permissions.
     * Comportement "fail silently" pour éviter de révéler l'existence d'agents restreints.
     */
    public function get(string $key): ?SynapseAgent
    {
        $agent = $this->repository->findByKey($key);

        if (null === $agent) {
            return null;
        }

        // Vérifier les permissions
        if (!$this->permissionChecker->canUseAgent($agent)) {
            return null; // Accès refusé, on retourne null silencieusement
        }

        return $agent;
    }
}
