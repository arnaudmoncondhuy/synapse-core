<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;

/**
 * Interface pour la politique de rétention RGPD des conversations.
 *
 * Permet de définir les règles de purge automatique (Nettoyage de la base de données).
 * Vous pouvez ainsi implémenter des durées de rétention différentes selon le type
 * d'utilisateur ou de conversation.
 */
interface RetentionPolicyInterface
{
    /**
     * Retourne la durée de conservation par défaut en jours.
     */
    public function getRetentionDays(): int;

    /**
     * Évalue individuellement si une conversation doit être supprimée.
     *
     * @param SynapseConversation $conversation La conversation à tester.
     *
     * @return bool True si elle doit être purgée immédiatement.
     */
    public function shouldPurge(SynapseConversation $conversation): bool;

    /**
     * Hook appelé juste avant la suppression physique d'une conversation.
     *
     * Idéal pour archiver les données anonymisées ou logger l'action de purge.
     */
    public function beforePurge(SynapseConversation $conversation): void;

    /**
     * Hook appelé à la fin d'un processus global de nettoyage.
     *
     * @param int $purgedCount Le nombre total de conversations supprimées lors de ce cycle.
     */
    public function afterPurge(int $purgedCount): void;
}
