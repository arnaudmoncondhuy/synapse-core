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
 * @method SynapseVectorMemory|null findOneBy(array<string, mixed> $criteria, array<string, string> $orderBy = null)
 * @method SynapseVectorMemory[] findAll()
 * @method SynapseVectorMemory[] findBy(array<string, mixed> $criteria, array<string, string> $orderBy = null, $limit = null, $offset = null)
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

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vide les vecteurs mémoire (conserve les entrées mais efface l'embedding).
     * Utilisé lors d'un changement de modèle d'embedding.
     */
    public function clearAllEmbeddings(): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('UPDATE '.SynapseVectorMemory::class.' v SET v.embedding = :empty')
            ->setParameter('empty', '[]')
            ->execute();
    }
}
