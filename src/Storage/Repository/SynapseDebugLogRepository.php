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
     * Récupère un log de debug par ID.
     */
    public function findByDebugId(string $debugId): ?SynapseDebugLog
    {
        return $this->findOneBy(['debugId' => $debugId]);
    }

    /**
     * Récupère les métadonnées des logs récents pour la liste (sans le payload complet).
     *
     * @return array<int, array{debugId: string, createdAt: \DateTimeImmutable, module: string|null, model: string|null, usage: array<string, mixed>|null}>
     */
    public function findRecent(int $limit = 50): array
    {
        /** @var array<int, array{debugId: string, createdAt: \DateTimeImmutable, data: array<string, mixed>}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.debugId', 'd.createdAt', 'd.data')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $data = $row['data'];

            return [
                'debugId' => $row['debugId'],
                'createdAt' => $row['createdAt'],
                'module' => $data['module'] ?? null,
                'model' => $data['model'] ?? null,
                'usage' => $data['usage'] ?? $data['token_usage'] ?? null,
            ];
        }, $rows);
    }

    /**
     * Supprime tous les logs de debug.
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
