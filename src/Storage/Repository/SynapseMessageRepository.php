<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * Repository pour l'entité SynapseMessage.
 *
 * @template T of SynapseMessage
 *
 * @extends ServiceEntityRepository<T>
 *
 * Note : Ce repository est abstrait car SynapseMessage est une MappedSuperclass.
 *        Les projets doivent créer leur propre repository qui étend celui-ci.
 */
abstract class SynapseMessageRepository extends ServiceEntityRepository
{
    /**
     * Trouve les messages d'une conversation.
     *
     * @param SynapseConversation $conversation SynapseConversation
     * @param int $limit Nombre maximum de messages (0 = illimité)
     *
     * @return SynapseMessage[] Liste des messages
     */
    public function findByConversation(SynapseConversation $conversation, int $limit = 0): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.createdAt', 'ASC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var SynapseMessage[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Compte le nombre total de messages pour aujourd'hui.
     *
     * @return int Nombre de messages
     */
    public function countToday(): int
    {
        $start = new \DateTimeImmutable('today');

        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.createdAt >= :start')
            ->setParameter('start', $start)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère les statistiques d'utilisation des tokens par jour (7 derniers jours).
     *
     * @return array<string, int> [date => total_tokens]
     */
    public function getTokenUsageStats(): array
    {
        $since = new \DateTimeImmutable('-7 days');

        /** @var array<int, array{day: string, total: float|int|string|null}> $results */
        $results = $this->createQueryBuilder('m')
            ->select('SUBSTRING(m.createdAt, 1, 10) as day, SUM(m.totalTokens) as total')
            ->where('m.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('day')
            ->orderBy('day', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $stats = [];
        foreach ($results as $row) {
            $stats[$row['day']] = (int) $row['total'];
        }

        return $stats;
    }

    /**
     * Trouve les messages avec un mauvais feedback.
     *
     * @param int $limit Limite
     *
     * @return SynapseMessage[] Messages
     */
    public function findNegativeFeedback(int $limit = 50): array
    {
        /** @var SynapseMessage[] $result */
        $result = $this->createQueryBuilder('m')
            ->where('m.feedback = -1')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Trouve les messages bloqués par la sécurité.
     *
     * @param int $limit Limite
     *
     * @return SynapseMessage[] Messages
     */
    public function findBlocked(int $limit = 50): array
    {
        /** @var SynapseMessage[] $result */
        $result = $this->createQueryBuilder('m')
            ->where('m.blocked = true')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Calcule la répartition des rôles sur le dernier mois.
     *
     * @return array<string, int> [role => count]
     */
    public function getRoleDistribution(): array
    {
        $since = new \DateTimeImmutable('-30 days');

        /** @var array<int, array{role: \BackedEnum|string|\UnitEnum, count: int|string}> $results */
        $results = $this->createQueryBuilder('m')
            ->select('m.role, COUNT(m.id) as count')
            ->where('m.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('m.role')
            ->getQuery()
            ->getArrayResult();

        $dist = [];
        foreach ($results as $row) {
            $roleVal = $row['role'];
            $role = 'unknown';
            if (is_string($roleVal)) {
                $role = $roleVal;
            } elseif ($roleVal instanceof \BackedEnum) {
                $role = (string) $roleVal->value;
            } elseif ($roleVal instanceof \UnitEnum) {
                $role = $roleVal->name;
            }
            $dist[$role] = (int) $row['count'];
        }

        return $dist;
    }
}
