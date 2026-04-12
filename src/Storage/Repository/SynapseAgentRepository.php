<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\AgentValidator;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\CannotActivateException;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\DeactivationCascade;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseAgent>
 */
class SynapseAgentRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly SynapseWorkflowRepository $workflowRepo,
        private readonly AgentValidator $agentValidator,
    ) {
        parent::__construct($registry, SynapseAgent::class);
    }

    /**
     * Active un agent. Centralise toute la logique de validation
     * d'activation (preset explicite encore valide ?).
     *
     * @throws CannotActivateException si l'agent ne peut pas être activé
     */
    public function activate(SynapseAgent $agent): void
    {
        if (!$this->agentValidator->isValid($agent)) {
            throw new CannotActivateException($agent->getName(), $this->agentValidator->getInvalidReason($agent) ?? 'agent invalide');
        }

        $agent->setIsActive(true);
    }

    /**
     * Trouve toutes les agents actives, triées par ordre d'affichage.
     *
     * @return array<int, SynapseAgent>
     */
    public function findAllActive(): array
    {
        /** @var array<int, SynapseAgent> $result */
        $result = $this->createQueryBuilder('m')
            ->andWhere('m.isActive = true')
            ->andWhere('m.visibleInChat = true')
            ->andWhere('m.isSandbox = false')
            ->orderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Trouve toutes les agents triées (builtin d'abord, puis par ordre d'affichage).
     * Utilisé pour l'affichage admin. Exclut les agents sandbox.
     *
     * @return array<int, SynapseAgent>
     */
    public function findAllOrdered(): array
    {
        /** @var array<int, SynapseAgent> $result */
        $result = $this->createQueryBuilder('m')
            ->andWhere('m.isSandbox = false')
            ->orderBy('m.isBuiltin', 'DESC')
            ->addOrderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Trouve un agent par sa clé unique (inclut les sandbox — nécessaire pour la résolution).
     */
    public function findByKey(string $key): ?SynapseAgent
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * Retourne tous les agents sandbox (pour le cleanup MCP).
     *
     * @return array<int, SynapseAgent>
     */
    public function findSandbox(): array
    {
        return $this->findBy(['isSandbox' => true]);
    }

    /**
     * Retourne les agents qui utilisent explicitement un preset donné
     * (ignore ceux qui pointent vers le preset actif global via null).
     *
     * @return array<int, SynapseAgent>
     */
    public function findByModelPreset(SynapseModelPreset $preset): array
    {
        /** @var array<int, SynapseAgent> $result */
        $result = $this->createQueryBuilder('a')
            ->andWhere('a.modelPreset = :preset')
            ->setParameter('preset', $preset)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Désactive un agent et propage la désactivation aux workflows qui le
     * référencent.
     *
     * Chaînage : seul le niveau n+1 (workflows) est connu ici. Les niveaux
     * supérieurs (preset, modèle) n'ont jamais besoin d'importer le
     * {@see SynapseWorkflowRepository}.
     *
     * Idempotent — un appel sur un agent déjà inactif ne touche pas l'agent
     * mais re-parcourt la cascade pour garantir que les workflows liés sont
     * bien eux aussi inactifs (défensif). Le caller reste responsable du
     * flush.
     */
    public function deactivate(SynapseAgent $agent): DeactivationCascade
    {
        $cascade = DeactivationCascade::empty();

        if ($agent->isActive()) {
            $agent->setIsActive(false);
            $cascade = $cascade->withAgent($agent->getName());
        }

        return $cascade->merge($this->workflowRepo->deactivateAllByAgentKey($agent->getKey()));
    }

    /**
     * Désactive en cascade tous les agents qui pointent explicitement vers un
     * preset (ignore ceux dont le preset est null = fallback global).
     *
     * Utilisé par {@see SynapseModelPresetRepository::deactivate()} pour
     * propager une désactivation de preset vers ses agents et workflows.
     */
    public function deactivateAllByModelPreset(SynapseModelPreset $preset): DeactivationCascade
    {
        $cascade = DeactivationCascade::empty();
        foreach ($this->findByModelPreset($preset) as $agent) {
            $cascade = $cascade->merge($this->deactivate($agent));
        }

        return $cascade;
    }
}
