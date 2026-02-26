# ConversationManager

Le `ConversationManager` est le gardien de l'historique et de la sécurité des données. Il permet de manipuler les discussions, de les chiffrer et de s'assurer que seuls les utilisateurs autorisés y ont accès.

## 🛠 Pourquoi l'utiliser ?

*   **CRUD Facile** : Créez, récupérez ou supprimez des conversations en une méthode.
*   **Sécurité intégrée** : Le chiffrement et la vérification des permissions sont appliqués automatiquement.
*   **Context Thread-Local** : Garde en mémoire la conversation "active" pour faciliter vos traitements.

---

## 📋 Méthodes principales

| Méthode | Rôle |
| :--- | :--- |
| `createConversation(...)` | Initialise un nouveau fil de discussion persistant. |
| `saveMessage(...)` | Enregistre un message utilisateur ou assistant. |
| `getMessages(...)` | Récupère l'historique (déchiffré automatiquement). |
| `getConversation(...)` | Récupère une conversation avec vérification des droits. |

---

## 🚀 Exemple : Gestion manuelle d'une conversation

=== "ConversationService.php"

    ```php
    namespace App\Service;

    use ArnaudMoncondhuy\SynapseCore\Core\Manager\ConversationManager;
    use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;

    class ConversationService
    {
        public function __construct(private ConversationManager $manager) {}

        public function initChat($user)
        {
            // Créer
            $conv = $this->manager->createConversation($user, "Ma discussion");
            
            // Ajouter un message système
            $this->manager->saveMessage($conv, MessageRole::SYSTEM, "Tu es un assistant utile.");
            
            return $conv;
        }
    }
    ```

---

## 💡 Conseils d'utilisation

> [!IMPORTANT]
> **Chiffrement** : Si vous avez configuré un `EncryptionServiceInterface`, le `ConversationManager` chiffrera le contenu des messages avant de les envoyer en base de données sans aucune action de votre part.

*   **Permissions** : Utilisez toujours `getConversation()` plutôt que de passer par le repository Doctrine directement, afin de bénéficier de la validation de sécurité automatique.

---



