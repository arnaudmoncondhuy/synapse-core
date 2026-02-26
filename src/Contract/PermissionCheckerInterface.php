<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;

/**
 * Interface pour la gestion fine des droits d'accès.
 *
 * Permet au bundle de déléguer la sécurité au système de votre application (Voters, ACL).
 * Le `ChatService` utilise ce service avant d'autoriser la lecture ou l'écriture
 * dans une conversation existante.
 */
interface PermissionCheckerInterface
{
    /**
     * Vérifie si l'utilisateur actuel peut accéder au contenu d'une conversation.
     *
     * @param SynapseConversation $conversation La conversation visée.
     *
     * @return bool True si la consultation est autorisée.
     */
    public function canView(SynapseConversation $conversation): bool;

    /**
     * Vérifie si l'utilisateur peut envoyer un nouveau message dans cette conversation.
     */
    public function canEdit(SynapseConversation $conversation): bool;

    /**
     * Vérifie si l'utilisateur peut supprimer cette conversation.
     */
    public function canDelete(SynapseConversation $conversation): bool;

    /**
     * Vérifie les droits d'accès à l'interface d'administration `/synapse/admin`.
     */
    public function canAccessAdmin(): bool;

    /**
     * Vérifie si l'utilisateur peut créer une nouvelle conversation.
     */
    public function canCreateConversation(): bool;
}
