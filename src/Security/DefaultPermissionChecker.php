<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Implémentation par défaut du vérificateur de permissions
 *
 * Pattern standard:
 * - Propriétaire peut voir/éditer/supprimer ses conversations
 * - Admin peut tout voir (configurable)
 * - Mode développement: accès total si pas de security
 */
class DefaultPermissionChecker implements PermissionCheckerInterface
{
    public function __construct(
        private ?Security $security = null,
        private ?AuthorizationCheckerInterface $authChecker = null,
        private string $adminRole = 'ROLE_ADMIN'
    ) {}

    public function canView(SynapseConversation $conversation): bool
    {
        // Pattern 1: Pas d'auth = accès refusé par défaut pour la sécurité
        if ($this->security === null) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof ConversationOwnerInterface) {
            return false;
        }

        // Pattern 2: Admin peut tout voir
        if ($this->authChecker?->isGranted($this->adminRole)) {
            return true;
        }

        // Pattern 3: Propriétaire peut voir
        $owner = $conversation->getOwner();
        if ($owner === null) {
            return false;
        }

        return $owner->getIdentifier() === $user->getIdentifier();
    }

    public function canEdit(SynapseConversation $conversation): bool
    {
        // Plus strict: seul le propriétaire peut éditer
        if ($this->security === null) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof ConversationOwnerInterface) {
            return false;
        }

        $owner = $conversation->getOwner();
        if ($owner === null) {
            return false;
        }

        return $owner->getIdentifier() === $user->getIdentifier();
    }

    public function canDelete(SynapseConversation $conversation): bool
    {
        // Même logique que canEdit
        return $this->canEdit($conversation);
    }

    public function canAccessAdmin(): bool
    {
        if ($this->authChecker === null) {
            return false; // Strict par défaut : pas d'admin sans sécurité configurée
        }

        return $this->authChecker->isGranted($this->adminRole);
    }

    public function canCreateConversation(): bool
    {
        if ($this->security === null) {
            return true; // On autorise le chat par défaut si pas de security (mode ouvert)
        }

        return $this->security->getUser() !== null;
    }
}
