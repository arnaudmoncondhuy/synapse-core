<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseCodeExecution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Audit trail des exécutions de code via `code_execute` — Chantier E.
 *
 * @extends ServiceEntityRepository<SynapseCodeExecution>
 */
class SynapseCodeExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseCodeExecution::class);
    }

    /**
     * Récupère toutes les exécutions d'un workflow run donné, ordonnées
     * chronologiquement. Utile pour reconstituer l'historique complet
     * des exécutions code d'un run depuis l'admin.
     *
     * @return list<SynapseCodeExecution>
     */
    public function findByWorkflowRunId(string $workflowRunId): array
    {
        return $this->createQueryBuilder('ce')
            ->andWhere('ce.workflowRunId = :runId')
            ->setParameter('runId', $workflowRunId)
            ->orderBy('ce.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère toutes les exécutions liées à un debug LLM. Typiquement 1,
     * mais peut être plusieurs si un turn a enchaîné plusieurs `code_execute`.
     *
     * @return list<SynapseCodeExecution>
     */
    public function findByDebugId(string $debugId): array
    {
        return $this->createQueryBuilder('ce')
            ->andWhere('ce.debugId = :debugId')
            ->setParameter('debugId', $debugId)
            ->orderBy('ce.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre total d'exécutions (succès + échec). Utile pour
     * dashboards admin.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('ce')
            ->select('COUNT(ce.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
