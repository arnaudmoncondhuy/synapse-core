# PermissionCheckerInterface

L'interface `PermissionCheckerInterface` permet de déléguer la logique de sécurité de Synapse Core à votre système de droits existant (Voters Symfony, ACL, etc.).

## 🛠 Pourquoi l'utiliser ?

*   **Intégration native** : Utilisez vos rôles (`ROLE_ADMIN`, `ROLE_USER`) pour contrôler l'accès aux fils de discussion.
*   **Isolation** : Garantir qu'un utilisateur ne puisse ni voir ni modifier les conversations des autres.
*   **Multi-niveaux** : Distinguer le droit de lecture, d'édition et de suppression.

---

## 📋 Résumé du Contrat

| Méthode | Cible | Rôle |
| :--- | :--- | :--- |
| `canView($conversation)` | Conversation | Autorise ou non la lecture. |
| `canEdit($conversation)` | Conversation | Autorise ou non l'envoi de messages. |
| `canDelete($conversation)` | Conversation | Autorise ou non la suppression/archivage. |
| `canAccessAdmin()` | - | Vérifie l'accès à `/synapse/admin`. |
| `canCreateConversation()`| - | Autorise la création d'un nouveau chat. |

---

## 🚀 Exemple : Implémentation via Symfony Security

=== "SynapseVoterChecker.php"

    ```php
    namespace App\Synapse\Security;

    use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
    use Symfony\Bundle\SecurityBundle\Security;

    class SynapseVoterChecker implements PermissionCheckerInterface
    {
        public function __construct(private Security $security) {}

        public function canView($conversation): bool
        {
            return $this->security->isGranted('VIEW', $conversation);
        }

        public function canEdit($conversation): bool
        {
            return $this->security->isGranted('EDIT', $conversation);
        }

        public function canDelete($conversation): bool
        {
            // Seuls les admins peuvent supprimer
            return $this->security->isGranted('ROLE_ADMIN');
        }

        public function canAccessAdmin(): bool
        {
            return $this->security->isGranted('ROLE_ADMIN');
        }

        public function canCreateConversation(): bool
        {
            // Tout utilisateur connecté peut créer un chat
            return $this->security->getUser() !== null;
        }
    }
    ```

---

## 💡 Conseils d'implémentation

*   **Délégation** : Si vous ne souhaitez pas gérer de permissions complexes, vous pouvez laisser le bundle utiliser `DefaultPermissionChecker`. Notez que par défaut, l'accès à l'administration est **bloqué** si aucun système de sécurité n'est configuré (posture "Secure by Default").
*   **Performance** : Ces méthodes sont appelées à chaque accès aux messages ou au dashboard. Veillez à ce qu'elles ne fassent pas de requêtes SQL lourdes.

---


