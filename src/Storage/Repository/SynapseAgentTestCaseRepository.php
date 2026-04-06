<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentTestCase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseAgentTestCase>
 */
class SynapseAgentTestCaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseAgentTestCase::class);
    }

    /**
     * Retourne les cas de test actifs d'un agent, triés par ordre d'exécution.
     *
     * @return array<int, SynapseAgentTestCase>
     */
    public function findActiveForAgent(SynapseAgent $agent): array
    {
        /** @var array<int, SynapseAgentTestCase> $result */
        $result = $this->createQueryBuilder('t')
            ->andWhere('t.agent = :agent')
            ->andWhere('t.isActive = true')
            ->setParameter('agent', $agent)
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Tous les cas d'un agent (actifs et inactifs), pour affichage admin.
     *
     * @return array<int, SynapseAgentTestCase>
     */
    public function findAllForAgent(SynapseAgent $agent): array
    {
        /** @var array<int, SynapseAgentTestCase> $result */
        $result = $this->createQueryBuilder('t')
            ->andWhere('t.agent = :agent')
            ->setParameter('agent', $agent)
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
