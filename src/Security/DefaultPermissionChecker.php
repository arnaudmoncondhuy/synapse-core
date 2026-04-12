<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Implémentation par défaut du vérificateur de permissions.
 *
 * Pattern standard:
 * - Propriétaire peut voir/éditer/supprimer ses conversations
 * - Admin peut tout voir (configurable)
 * - Mode développement: accès total si pas de security
 */
#[AsAlias(id: PermissionCheckerInterface::class)]
class DefaultPermissionChecker implements PermissionCheckerInterface
{
    public function __construct(
        private ?Security $security = null,
        private ?AuthorizationCheckerInterface $authChecker = null,
        #[Autowire('%synapse.security.admin_role%')]
        private string $adminRole = 'ROLE_ADMIN',
        #[Autowire('%synapse.security.mcp_trusted%')]
        private bool $mcpTrusted = false,
        private ?RequestStack $requestStack = null,
    ) {
    }

    public function canView(SynapseConversation $conversation): bool
    {
        // Pattern 1: Pas d'auth = accès refusé par défaut pour la sécurité
        if (null === $this->security) {
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
        if (null === $owner) {
            return false;
        }

        return $owner->getIdentifier() === $user->getIdentifier();
    }

    public function canEdit(SynapseConversation $conversation): bool
    {
        // Plus strict: seul le propriétaire peut éditer
        if (null === $this->security) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof ConversationOwnerInterface) {
            return false;
        }

        $owner = $conversation->getOwner();
        if (null === $owner) {
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
        // Bypass MCP : si le flag est activé ET qu'on traite une requête /_mcp,
        // on considère l'appelant comme admin. Le bypass est strictement scopé à
        // la route MCP pour ne pas ouvrir les autres surfaces (admin, API).
        // Sans ce bypass, les tools MCP échouent en "Admin role required" car
        // une requête MCP n'a aucune session HTTP authentifiée.
        if ($this->mcpTrusted && $this->isMcpRequest()) {
            return true;
        }

        if (null === $this->authChecker) {
            return false; // Strict par défaut : pas d'admin sans sécurité configurée
        }

        return $this->authChecker->isGranted($this->adminRole);
    }

    /**
     * Détecte si la requête courante cible l'endpoint MCP.
     * Hors contexte HTTP (CLI, messenger…) : false.
     */
    private function isMcpRequest(): bool
    {
        $request = $this->requestStack?->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        return str_starts_with($request->getPathInfo(), '/_mcp');
    }

    public function canCreateConversation(): bool
    {
        if (null === $this->security) {
            return true; // On autorise le chat par défaut si pas de security (mode ouvert)
        }

        return null !== $this->security->getUser();
    }

    public function canUseAgent(SynapseAgent $agent): bool
    {
        $accessControl = $agent->getAccessControl();

        // Pas de restriction configurée = agent public (accessible à tous)
        if (null === $accessControl || (empty($accessControl['roles']) && empty($accessControl['userIdentifiers']))) {
            return true;
        }

        // Si restrictions configurées mais pas d'auth = refusé
        if (null === $this->security) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user) {
            return false;
        }

        $allowedRoles = $accessControl['roles'] ?? [];
        $allowedUserIdentifiers = $accessControl['userIdentifiers'] ?? [];

        // Vérification 1 : Vérifier si l'utilisateur a au moins un des rôles autorisés
        foreach ($allowedRoles as $role) {
            if ($this->authChecker?->isGranted($role)) {
                return true;
            }
        }

        // Vérification 2 : Vérifier si l'identifiant utilisateur est dans la liste
        if ($user instanceof ConversationOwnerInterface) {
            $userIdentifier = $user->getIdentifier();

            if (in_array($userIdentifier, $allowedUserIdentifiers, true)) {
                return true;
            }
        }

        return false;
    }
}
