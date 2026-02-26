<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ConversationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité SynapseConversation
 *
 * @template T of SynapseConversation
 * @extends ServiceEntityRepository<T>
 *
 * Note : Ce repository est abstrait car SynapseConversation est une MappedSuperclass.
 *        Les projets doivent créer leur propre repository qui étend celui-ci.
 *
 * @example
 * ```php
 * use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConversationRepository as BaseSynapseConversationRepository;
 *
 * class SynapseConversationRepository extends BaseSynapseConversationRepository
 * {
 *     public function __construct(ManagerRegistry $registry)
 *     {
 *         parent::__construct($registry, SynapseConversation::class);
 *     }
 * }
 * ```
 */
abstract class SynapseConversationRepository extends ServiceEntityRepository
{
    /**
     * Trouve les conversations actives d'un propriétaire
     *
     * @param ConversationOwnerInterface $owner Propriétaire
     * @param int $limit Nombre maximum de résultats
     * @return SynapseConversation[] Liste des conversations
     */
    public function findActiveByOwner(ConversationOwnerInterface $owner, int $limit = 50): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.owner = :owner')
            ->andWhere('c.status = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', ConversationStatus::ACTIVE)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les conversations plus anciennes que X jours (pour purge RGPD)
     *
     * @param int $days Nombre de jours de rétention
     * @return SynapseConversation[] Conversations à purger
     */
    public function findOlderThan(int $days): array
    {
        $date = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('c')
            ->where('c.updatedAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les conversations actives des dernières 24h
     *
     * @return int Nombre de conversations
     */
    public function countActiveLast24h(): int
    {
        $yesterday = new \DateTimeImmutable('-24 hours');

        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.createdAt >= :yesterday')
            ->andWhere('c.status = :status')
            ->setParameter('yesterday', $yesterday)
            ->setParameter('status', ConversationStatus::ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte les utilisateurs actifs depuis une date
     *
     * @param \DateTimeInterface $since Date de début
     * @return int Nombre d'utilisateurs uniques
     */
    public function countActiveUsersSince(\DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.owner)')
            ->where('c.updatedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Trouve les conversations par statut
     *
     * @param ConversationStatus $status Statut recherché
     * @param int $limit Nombre maximum de résultats
     * @return SynapseConversation[] Liste des conversations
     */
    public function findByStatus(ConversationStatus $status, int $limit = 100): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', $status)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche dans les conversations (titre, résumé)
     *
     * Note : La recherche dans les titres chiffrés n'est pas possible.
     *        Seule la recherche dans les résumés (non chiffrés) fonctionne.
     *
     * @param string $query Terme de recherche
     * @param ConversationOwnerInterface|null $owner Filtrer par propriétaire
     * @param int $limit Nombre maximum de résultats
     * @return SynapseConversation[] Résultats de recherche
     */
    public function search(string $query, ?ConversationOwnerInterface $owner = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.summary LIKE :query')
            ->andWhere('c.status != :deleted')
            ->setParameter('query', '%' . $query . '%')
            ->setParameter('deleted', ConversationStatus::DELETED)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults($limit);

        if ($owner !== null) {
            $qb->andWhere('c.owner = :owner')
                ->setParameter('owner', $owner);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Supprime définitivement les conversations (hard delete)
     *
     * Utilisé par la commande de purge RGPD.
     *
     * @param array $conversations Conversations à supprimer
     * @return int Nombre de conversations supprimées
     */
    public function hardDelete(array $conversations): int
    {
        $count = 0;
        $em = $this->getEntityManager();

        foreach ($conversations as $conversation) {
            $em->remove($conversation);
            $count++;
        }

        $em->flush();

        return $count;
    }
}
