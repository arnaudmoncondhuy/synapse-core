<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseMission>
 */
class SynapseMissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseMission::class);
    }

    /**
     * Trouve toutes les missions actives, triées par ordre d'affichage.
     *
     * @return array<int, SynapseMission>
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.isActive = true')
            ->orderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve toutes les missions triées (builtin d'abord, puis par ordre d'affichage).
     * Utilisé pour l'affichage admin.
     *
     * @return array<int, SynapseMission>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.isBuiltin', 'DESC')
            ->addOrderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve une mission par sa clé unique.
     */
    public function findByKey(string $key): ?SynapseMission
    {
        return $this->findOneBy(['key' => $key]);
    }
}
