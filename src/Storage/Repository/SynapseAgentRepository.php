<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseAgent>
 */
class SynapseAgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseAgent::class);
    }

    /**
     * Trouve toutes les agents actives, triées par ordre d'affichage.
     *
     * @return array<int, SynapseAgent>
     */
    public function findAllActive(): array
    {
        /** @var array<int, SynapseAgent> $result */
        $result = $this->createQueryBuilder('m')
            ->andWhere('m.isActive = true')
            ->orderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Trouve toutes les agents triées (builtin d'abord, puis par ordre d'affichage).
     * Utilisé pour l'affichage admin.
     *
     * @return array<int, SynapseAgent>
     */
    public function findAllOrdered(): array
    {
        /** @var array<int, SynapseAgent> $result */
        $result = $this->createQueryBuilder('m')
            ->orderBy('m.isBuiltin', 'DESC')
            ->addOrderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Trouve un agent par sa clé unique.
     */
    public function findByKey(string $key): ?SynapseAgent
    {
        return $this->findOneBy(['key' => $key]);
    }
}
