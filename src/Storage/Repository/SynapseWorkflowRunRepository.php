<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseWorkflowRun>
 */
class SynapseWorkflowRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseWorkflowRun::class);
    }

    /**
     * Retourne un run par son UUID logique (propagé dans `AgentContext::$workflowRunId`).
     */
    public function findByWorkflowRunId(string $workflowRunId): ?SynapseWorkflowRun
    {
        return $this->findOneBy(['workflowRunId' => $workflowRunId]);
    }

    /**
     * Récupère les métadonnées des runs récents d'un workflow — sans charger les
     * colonnes JSON `input`/`output` (grosses payloads). Même pattern que
     * {@see SynapseDebugLogRepository::findRoots()}.
     *
     * @return array<int, array{
     *     id: int,
     *     workflowRunId: string,
     *     workflowVersion: int,
     *     status: WorkflowRunStatus,
     *     currentStepIndex: int,
     *     stepsCount: int,
     *     startedAt: \DateTimeImmutable,
     *     completedAt: \DateTimeImmutable|null,
     *     userId: string|null,
     *     totalTokens: int|null,
     *     errorMessage: string|null,
     * }>
     */
    public function findRecentForWorkflow(SynapseWorkflow $workflow, int $limit = 50): array
    {
        /** @var array<int, array{id: int, workflowRunId: string, workflowVersion: int, status: WorkflowRunStatus, currentStepIndex: int, stepsCount: int, startedAt: \DateTimeImmutable, completedAt: \DateTimeImmutable|null, userId: string|null, totalTokens: int|null, errorMessage: string|null}> $result */
        $result = $this->createQueryBuilder('r')
            ->select('r.id', 'r.workflowRunId', 'r.workflowVersion', 'r.status', 'r.currentStepIndex', 'r.stepsCount', 'r.startedAt', 'r.completedAt', 'r.userId', 'r.totalTokens', 'r.errorMessage')
            ->andWhere('r.workflow = :workflow')
            ->setParameter('workflow', $workflow)
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return $result;
    }

    /**
     * Compte les runs par statut pour un workflow donné (utilisé pour les KPIs admin).
     *
     * @return array<string, int> ex: ['completed' => 12, 'failed' => 3, 'running' => 1]
     */
    public function countByStatus(SynapseWorkflow $workflow): array
    {
        /** @var array<int, array{status: WorkflowRunStatus, total: int}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status', 'COUNT(r.id) AS total')
            ->andWhere('r.workflow = :workflow')
            ->setParameter('workflow', $workflow)
            ->groupBy('r.status')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof WorkflowRunStatus ? $row['status']->value : (string) $row['status'];
            $result[$status] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Supprime tous les runs dont le workflowKey est dans la liste fournie (cleanup MCP sandbox).
     *
     * @param string[] $workflowKeys
     *
     * @return int nombre de runs supprimés
     */
    public function deleteByWorkflowKeys(array $workflowKeys): int
    {
        if ([] === $workflowKeys) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.workflowKey IN (:keys)')
            ->setParameter('keys', $workflowKeys)
            ->getQuery()
            ->execute();
    }
}
