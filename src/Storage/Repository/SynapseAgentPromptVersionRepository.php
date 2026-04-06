<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\PromptVersionStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentPromptVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseAgentPromptVersion>
 */
class SynapseAgentPromptVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseAgentPromptVersion::class);
    }

    /**
     * Historique complet d'un agent, du plus récent au plus ancien.
     *
     * @return array<int, SynapseAgentPromptVersion>
     */
    public function findByAgent(SynapseAgent $agent, int $limit = 100): array
    {
        /** @var array<int, SynapseAgentPromptVersion> $result */
        $result = $this->createQueryBuilder('v')
            ->andWhere('v.agent = :agent')
            ->setParameter('agent', $agent)
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Retourne la version actuellement marquée comme active pour cet agent.
     * Une seule version active par agent à un instant T (invariant
     * `PromptVersionRecorder`).
     */
    public function findActiveForAgent(SynapseAgent $agent): ?SynapseAgentPromptVersion
    {
        return $this->findOneBy(['agent' => $agent, 'isActive' => true]);
    }

    /**
     * Retourne la version la plus récente pour cet agent (qu'elle soit active ou
     * non), utilisée par `PromptVersionRecorder` pour l'idempotence : si la
     * dernière version a exactement le même contenu que ce qu'on s'apprête à
     * snapshotter, on n'en crée pas une nouvelle.
     */
    public function findLatestForAgent(SynapseAgent $agent): ?SynapseAgentPromptVersion
    {
        /** @var SynapseAgentPromptVersion|null $result */
        $result = $this->createQueryBuilder('v')
            ->andWhere('v.agent = :agent')
            ->setParameter('agent', $agent)
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function save(SynapseAgentPromptVersion $version, bool $flush = true): void
    {
        $this->getEntityManager()->persist($version);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * File d'attente des versions en `pending` (tous agents confondus) —
     * alimente la page de validation HITL de l'admin (Garde-fou #3).
     *
     * @return array<int, SynapseAgentPromptVersion>
     */
    public function findAllPending(int $limit = 100): array
    {
        /** @var array<int, SynapseAgentPromptVersion> $result */
        $result = $this->createQueryBuilder('v')
            ->andWhere('v.status = :status')
            ->setParameter('status', PromptVersionStatus::Pending->value)
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Versions pending d'un agent donné (file d'attente par agent).
     *
     * @return array<int, SynapseAgentPromptVersion>
     */
    public function findPendingForAgent(SynapseAgent $agent): array
    {
        /** @var array<int, SynapseAgentPromptVersion> $result */
        $result = $this->createQueryBuilder('v')
            ->andWhere('v.agent = :agent')
            ->andWhere('v.status = :status')
            ->setParameter('agent', $agent)
            ->setParameter('status', PromptVersionStatus::Pending->value)
            ->orderBy('v.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countPending(): int
    {
        /** @var int $result */
        $result = (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.status = :status')
            ->setParameter('status', PromptVersionStatus::Pending->value)
            ->getQuery()
            ->getSingleScalarResult();

        return $result;
    }
}
