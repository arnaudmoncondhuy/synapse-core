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
     * 
     * @return SynapseDebugLog[]
     */
    public function findRecent(int $limit = 50): array
    {
        /** @var array<int, SynapseDebugLog> $result */
        $result = $this->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Supprime tous les logs de debug
     */
    public function clearAll(): int
    {
        $result = $this->createQueryBuilder('d')
            ->delete()
            ->getQuery()
            ->execute();

        return is_int($result) ? $result : 0;
    }
}
