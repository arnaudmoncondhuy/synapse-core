<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseDebugLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseDebugLog>
 */
class SynapseDebugLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseDebugLog::class);
    }

    /**
     * Récupère un log de debug par ID
     */
    public function findByDebugId(string $debugId): ?SynapseDebugLog
    {
        return $this->findOneBy(['debugId' => $debugId]);
    }

    /**
     * Récupère les logs de debug récents
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime tous les logs de debug
     */
    public function clearAll(): mixed
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->getQuery()
            ->execute();
    }
}
