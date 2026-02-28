<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMission;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMissionRepository;

/**
 * Registry pour accéder aux missions configurées.
 *
 * Utilisé par :
 * - ContextBuilderSubscriber : résoudre une mission par clé
 * - SynapseTwigExtension : exposer synapse_get_missions()
 */
class MissionRegistry
{
    public function __construct(
        private SynapseMissionRepository $repository,
    ) {}

    /**
     * Récupère toutes les missions actives, indexées par clé.
     *
     * @return array<string, array> Missions indexées par key, converties en tableau
     */
    public function getAll(): array
    {
        $missions = $this->repository->findAllActive();
        $result = [];

        foreach ($missions as $mission) {
            $result[$mission->getKey()] = $mission->toArray();
        }

        return $result;
    }

    /**
     * Récupère une mission par sa clé unique.
     */
    public function get(string $key): ?SynapseMission
    {
        return $this->repository->findByKey($key);
    }
}
