# PermissionCheckerInterface

L'interface `PermissionCheckerInterface` délègue la gestion des droits d'accès au système de sécurité de votre application (Voters Symfony, ACL, etc.).

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface
```

## Contrat complet

```php
interface PermissionCheckerInterface
{
    public function canView(SynapseConversation $conversation): bool;
    public function canEdit(SynapseConversation $conversation): bool;
    public function canDelete(SynapseConversation $conversation): bool;
    public function canAccessAdmin(): bool;
    public function canCreateConversation(): bool;
    public function canUseAgent(SynapseAgent $agent): bool;
}
```

## Méthodes

| Méthode | Rôle |
|---------|------|
| `canView(SynapseConversation $conversation): bool` | Vérifie si l'utilisateur peut consulter le contenu d'une conversation. |
| `canEdit(SynapseConversation $conversation): bool` | Vérifie si l'utilisateur peut envoyer un message dans cette conversation. |
| `canDelete(SynapseConversation $conversation): bool` | Vérifie si l'utilisateur peut supprimer cette conversation. |
| `canAccessAdmin(): bool` | Vérifie les droits d'accès à l'interface d'administration `/synapse/admin`. |
| `canCreateConversation(): bool` | Vérifie si l'utilisateur peut créer une nouvelle conversation. |
| `canUseAgent(SynapseAgent $agent): bool` | Vérifie si l'utilisateur peut utiliser un agent spécifique. |

---

## Pourquoi l'utiliser ?

- **Intégration native** : déléguer à vos Voters Symfony existants.
- **Isolation** : garantir qu'un utilisateur ne puisse ni voir ni modifier les conversations des autres.
- **Contrôle d'accès aux agents** : filtrer les agents disponibles selon les rôles et identifiants.

!!! note "Secure by Default"
    Par défaut, sans implémentation personnalisée, l'accès à l'administration est bloqué. C'est la posture sécurisée par défaut.

---

## Exemple : Implémentation via Symfony Security

```php
namespace App\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use Symfony\Bundle\SecurityBundle\Security;

class AppPermissionChecker implements PermissionCheckerInterface
{
    public function __construct(private Security $security) {}

    public function canView(SynapseConversation $conversation): bool
    {
        return $this->security->isGranted('VIEW', $conversation);
    }

    public function canEdit(SynapseConversation $conversation): bool
    {
        return $this->security->isGranted('EDIT', $conversation);
    }

    public function canDelete(SynapseConversation $conversation): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }

    public function canAccessAdmin(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }

    public function canCreateConversation(): bool
    {
        return $this->security->getUser() !== null;
    }

    public function canUseAgent(SynapseAgent $agent): bool
    {
        $accessControl = $agent->getAccessControl();
        if ($accessControl === null) {
            return true; // Agent public
        }

        $user = $this->security->getUser();
        if ($user === null) {
            return false;
        }

        foreach ($accessControl['roles'] ?? [] as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return in_array(
            $user->getUserIdentifier(),
            $accessControl['userIdentifiers'] ?? [],
            true
        );
    }
}
```

Puis enregistrez dans `services.yaml` :

```yaml
services:
    ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface:
        class: App\Security\AppPermissionChecker
```

---

## Voir aussi

- [Contrôle d'accès aux agents](../../agent-access-control.md) — configuration détaillée
- [ConversationManager](../conversation-manager.md) — utilise ce checker pour chaque accès
