<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimit;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseSpendingLimit>
 */
class SynapseSpendingLimitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseSpendingLimit::class);
    }

    /**
     * Plafonds actifs pour un utilisateur (scope=user).
     *
     * @return SynapseSpendingLimit[]
     */
    public function findForUser(string $userId): array
    {
        return $this->findBy(
            ['scope' => SpendingLimitScope::USER, 'scopeId' => $userId],
            ['amount' => 'ASC']
        );
    }

    /**
     * Plafonds actifs pour un preset (scope=preset).
     *
     * @return SynapseSpendingLimit[]
     */
    public function findForPreset(int $presetId): array
    {
        return $this->findBy(
            ['scope' => SpendingLimitScope::PRESET, 'scopeId' => (string) $presetId],
            ['amount' => 'ASC']
        );
    }

    /**
     * Plafonds actifs pour une mission (scope=mission).
     *
     * @return SynapseSpendingLimit[]
     */
    public function findForMission(int $missionId): array
    {
        return $this->findBy(
            ['scope' => SpendingLimitScope::MISSION, 'scopeId' => (string) $missionId],
            ['amount' => 'ASC']
        );
    }
}
