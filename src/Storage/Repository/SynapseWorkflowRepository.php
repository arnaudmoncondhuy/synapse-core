<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseWorkflow>
 */
class SynapseWorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseWorkflow::class);
    }

    /**
     * Tous les workflows triés pour l'admin (builtin d'abord, puis sortOrder, puis nom).
     *
     * @return array<int, SynapseWorkflow>
     */
    public function findAllOrdered(): array
    {
        /** @var array<int, SynapseWorkflow> $result */
        $result = $this->createQueryBuilder('w')
            ->andWhere('w.isSandbox = false')
            ->orderBy('w.isBuiltin', 'DESC')
            ->addOrderBy('w.sortOrder', 'ASC')
            ->addOrderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Recherche un workflow actif par sa clé unique (appelable par le moteur Phase 8).
     * Inclut les sandbox — nécessaire pour l'exécution via MCP.
     */
    public function findActiveByKey(string $workflowKey): ?SynapseWorkflow
    {
        return $this->findOneBy(['workflowKey' => $workflowKey, 'isActive' => true]);
    }

    /**
     * Recherche par clé sans filtre (inclut sandbox — pour admin edit et MCP).
     */
    public function findByKey(string $workflowKey): ?SynapseWorkflow
    {
        return $this->findOneBy(['workflowKey' => $workflowKey]);
    }

    /**
     * Retourne tous les workflows sandbox (pour le cleanup MCP).
     *
     * @return array<int, SynapseWorkflow>
     */
    public function findSandbox(): array
    {
        return $this->findBy(['isSandbox' => true]);
    }
}
