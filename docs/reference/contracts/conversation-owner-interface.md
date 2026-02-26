# ConversationOwnerInterface

L'interface `ConversationOwnerInterface` est le pont entre votre système d'utilisateurs et Synapse Core. Elle permet d'identifier à qui appartient une discussion pour gérer la sécurité et l'isolation des données.

## 🛠 Pourquoi l'utiliser ?

*   **Multi-utilisateur** : Séparez hermétiquement les chats de l'utilisateur A de ceux de l'utilisateur B.
*   **RGPD & Conformité** : Liez les données de l'IA à une identité réelle pour les demandes de suppression ou d'accès.
*   **Flexibilité** : Le propriétaire peut être un utilisateur (`User`), mais aussi une `Organisation`, une `Equipe` ou n'importe quelle entité de votre projet.

---

## 📋 Résumé du Contrat

| Méthode | Rôle |
| :--- | :--- |
| `getId()` | Identifiant unique (string ou int) pour le stockage. |
| `getDisplayName()` | Nom utilisé pour l'affichage (logs, administration). |
| `getAvatarUrl()` | (Optionnel) URL de l'image de profil pour l'UI de chat. |

---

## 🚀 Exemple : Intégration avec UserBundle / Security

=== "User.php"

    ```php
    namespace App\Entity;

    use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
    use Doctrine\ORM\Mapping as ORM;

    #[ORM\Entity]
    class User implements ConversationOwnerInterface
    {
        #[ORM\Id, ORM\GeneratedValue, ORM\Column]
        private ?int $id = null;

        #[ORM\Column]
        private string $email;

        public function getId(): ?int { return $this->id; }

        public function getDisplayName(): string { return $this->email; }

        public function getAvatarUrl(): ?string { return null; }
    }
    ```

---

## 💡 Conseils d'implémentation

*   **Identifiant stable** : La valeur retournée par `getId()` est stockée en base de données dans la table des conversations. Assurez-vous qu'elle ne change pas.
*   **Accès rapide** : Dans vos contrôleurs, vous pouvez récupérer les conversations via le `ConversationManager` en lui passant simplement `$this->getUser()`.

---


