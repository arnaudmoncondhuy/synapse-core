<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Shared\Exception\CannotActivateException;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\DeactivationCascade;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\WorkflowValidator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

/**
 * @extends ServiceEntityRepository<SynapseWorkflow>
 */
class SynapseWorkflowRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        #[Lazy]
        private readonly WorkflowValidator $workflowValidator,
    ) {
        parent::__construct($registry, SynapseWorkflow::class);
    }

    /**
     * Active un workflow. Centralise la validation d'activation : toutes les
     * étapes doivent référencer des agents résolvables et actifs.
     *
     * @throws CannotActivateException si une étape référence un agent invalide
     */
    public function activate(SynapseWorkflow $workflow): void
    {
        if (!$this->workflowValidator->isValid($workflow)) {
            throw new CannotActivateException($workflow->getName(), $this->workflowValidator->getInvalidReason($workflow) ?? 'workflow invalide');
        }

        $workflow->setIsActive(true);
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

    /**
     * Retourne tous les workflows dont au moins une étape référence l'agent
     * passé en paramètre (via `definition.steps[].agent_name`).
     *
     * Scan en PHP plutôt qu'une requête JSON Postgres : le nombre de workflows
     * reste très raisonnable et la portabilité prévaut sur l'optimisation.
     *
     * @return array<int, SynapseWorkflow>
     */
    public function findByAgentKey(string $agentKey): array
    {
        $result = [];
        foreach ($this->findAll() as $workflow) {
            if ($this->workflowReferencesAgent($workflow, $agentKey)) {
                $result[] = $workflow;
            }
        }

        return $result;
    }

    /**
     * Désactive un workflow et renvoie le cascade associé.
     *
     * Feuille de la chaîne de désactivation : un workflow n'a pas d'entité
     * enfant à cascader. Idempotent — un appel sur un workflow déjà inactif
     * renvoie un cascade vide.
     *
     * Le caller reste responsable du flush.
     */
    public function deactivate(SynapseWorkflow $workflow): DeactivationCascade
    {
        if (!$workflow->isActive()) {
            return DeactivationCascade::empty();
        }

        $workflow->setIsActive(false);

        return DeactivationCascade::empty()->withWorkflow($workflow->getName());
    }

    /**
     * Désactive en cascade tous les workflows qui référencent l'agent donné.
     *
     * Utilisé par {@see SynapseAgentRepository::deactivate()} pour propager
     * une désactivation d'agent vers ses workflows. Le caller (directement
     * ou indirectement) reste responsable du flush.
     */
    public function deactivateAllByAgentKey(string $agentKey): DeactivationCascade
    {
        $cascade = DeactivationCascade::empty();
        foreach ($this->findByAgentKey($agentKey) as $workflow) {
            $cascade = $cascade->merge($this->deactivate($workflow));
        }

        return $cascade;
    }

    private function workflowReferencesAgent(SynapseWorkflow $workflow, string $agentKey): bool
    {
        $steps = $workflow->getDefinition()['steps'] ?? [];
        if (!is_array($steps)) {
            return false;
        }

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            if (($step['agent_name'] ?? null) === $agentKey) {
                return true;
            }
        }

        return false;
    }
}
