<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;

/**
 * Interface pour la gestion fine des droits d'accès.
 *
 * Permet au bundle de déléguer la sécurité au système de votre application (Voters, ACL).
 * Le `ChatService` utilise ce service avant d'autoriser la lecture ou l'écriture
 * dans une conversation existante, et avant d'autoriser l'utilisation d'un agent.
 */
interface PermissionCheckerInterface
{
    /**
     * Vérifie si l'utilisateur actuel peut accéder au contenu d'une conversation.
     *
     * @param SynapseConversation $conversation la conversation visée
     *
     * @return bool true si la consultation est autorisée
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

    /**
     * Vérifie si l'utilisateur actuel peut utiliser un agent spécifique.
     *
     * Cette méthode est appelée par `AgentRegistry` pour filtrer les agents disponibles
     * et pour vérifier l'accès lors de l'utilisation d'un agent via `ChatService::ask(['agent' => 'key'])`.
     *
     * La logique par défaut vérifie :
     * - Si l'agent a un `accessControl` configuré (rôles et/ou identifiants utilisateur).
     * - Si `accessControl` est null ou vide, l'agent est considéré comme public.
     * - Si configuré, l'utilisateur doit avoir au moins un des rôles autorisés OU son identifiant
     *   doit être dans la liste des utilisateurs autorisés.
     *
     * @param SynapseAgent $agent l'agent dont on vérifie l'accès
     *
     * @return bool true si l'utilisateur peut utiliser cet agent
     */
    public function canUseAgent(SynapseAgent $agent): bool;
}
