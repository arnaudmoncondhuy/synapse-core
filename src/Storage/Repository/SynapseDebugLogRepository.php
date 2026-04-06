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
     * Récupère les métadonnées des logs récents pour la liste (sans le payload JSON).
     *
     * @return array<int, array{debugId: string, createdAt: \DateTimeImmutable, module: string|null, action: string|null, model: string|null, totalTokens: int|null}>
     */
    public function findRecent(int $limit = 50): array
    {
        /* @var array<int, array{debugId: string, createdAt: \DateTimeImmutable, module: string|null, action: string|null, model: string|null, totalTokens: int|null}> */
        return $this->createQueryBuilder('d')
            ->select('d.debugId', 'd.createdAt', 'd.module', 'd.action', 'd.model', 'd.totalTokens')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Récupère les métadonnées des logs "racine" (appels de premier niveau).
     *
     * Sont considérés comme racines :
     * - les appels sans parent (`parentRunId IS NULL`) — appels directs (chat, MCP, etc.) ;
     * - les appels d'origine `workflow` à depth 1 — steps de workflow, visibles comme
     *   entrées de premier niveau dans l'admin (le workflow runner les déclenche via
     *   `AgentResolver`, ils ont un `parentRunId` mais restent des racines visibles).
     *
     * @return array<int, array{debugId: string, createdAt: \DateTimeImmutable, module: string|null, action: string|null, model: string|null, totalTokens: int|null, origin: string, depth: int, workflowRunId: string|null}>
     */
    public function findRoots(int $limit = 50): array
    {
        /* @var array<int, array{debugId: string, createdAt: \DateTimeImmutable, module: string|null, action: string|null, model: string|null, totalTokens: int|null, origin: string, depth: int, workflowRunId: string|null}> */
        return $this->createQueryBuilder('d')
            ->select('d.debugId', 'd.createdAt', 'd.module', 'd.action', 'd.model', 'd.totalTokens', 'd.origin', 'd.depth', 'd.workflowRunId')
            ->where('d.parentRunId IS NULL OR (d.origin = :workflow AND d.depth = 1)')
            ->setParameter('workflow', 'workflow')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Récupère les logs enfants d'une exécution d'agent (appels imbriqués dont
     * `parentRunId` correspond au `agent_run_id` de l'appel parent).
     *
     * @return SynapseDebugLog[]
     */
    public function findChildrenOfRun(string $parentRunId): array
    {
        return $this->findBy(
            ['parentRunId' => $parentRunId],
            ['createdAt' => 'ASC'],
        );
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
