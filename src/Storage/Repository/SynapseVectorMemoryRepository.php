<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseVectorMemory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour SynapseVectorMemory.
 * 
 * @extends ServiceEntityRepository<SynapseVectorMemory>
 *
 * @method SynapseVectorMemory|null find($id, $lockMode = null, $lockVersion = null)
 * @method SynapseVectorMemory|null findOneBy(array $criteria, array $orderBy = null)
 * @method SynapseVectorMemory[]    findAll()
 * @method SynapseVectorMemory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SynapseVectorMemoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseVectorMemory::class);
    }

    public function add(SynapseVectorMemory $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SynapseVectorMemory $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
