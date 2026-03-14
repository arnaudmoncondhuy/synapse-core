<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimitLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseSpendingLimitLog>
 */
class SynapseSpendingLimitLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseSpendingLimitLog::class);
    }

    /**
     * Derniers N incidents de dépassement, les plus récents en premier.
     *
     * @return SynapseSpendingLimitLog[]
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.exceededAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Incidents sur une plage de dates, les plus récents en premier.
     *
     * @return SynapseSpendingLimitLog[]
     */
    public function findByPeriod(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.exceededAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('l.exceededAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre total d'incidents par scope sur une période.
     *
     * @return array<string, int>
     */
    public function countByScope(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.scope, COUNT(l.id) AS cnt')
            ->where('l.exceededAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('l.scope')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['scope']] = (int) $row['cnt'];
        }

        return $result;
    }
}
