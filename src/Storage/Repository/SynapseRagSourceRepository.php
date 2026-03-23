<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseRagSource>
 */
class SynapseRagSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseRagSource::class);
    }

    public function findBySlug(string $slug): ?SynapseRagSource
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return SynapseRagSource[]
     */
    public function findActive(): array
    {
        /** @var SynapseRagSource[] $result */
        $result = $this->createQueryBuilder('s')
            ->andWhere('s.isActive = true')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return SynapseRagSource[]
     */
    public function findAllOrdered(): array
    {
        /** @var SynapseRagSource[] $result */
        $result = $this->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function add(SynapseRagSource $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SynapseRagSource $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
