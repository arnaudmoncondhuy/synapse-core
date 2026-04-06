# ConversationManager

Le `ConversationManager` est le gestionnaire centralisé des conversations et de leur persistance. Il gère le cycle de vie des discussions avec chiffrement transparent, la vérification des permissions et le contexte de conversation active.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager
```

## Responsabilités

- Cycle de vie des conversations (CRUD) avec chiffrement transparent des données sensibles
- Gestion des messages et de l'historique
- Vérification des permissions d'accès (via `PermissionCheckerInterface`)
- Gestion du contexte thread-local (conversation active)

## Méthodes principales

| Méthode | Rôle |
|---------|------|
| `createConversation(ConversationOwnerInterface $owner, ?string $title): SynapseConversation` | Crée et persiste une nouvelle conversation. |
| `deleteConversation(SynapseConversation $conversation): void` | Supprime définitivement une conversation. |
| `getCurrentConversation(): ?SynapseConversation` | Retourne la conversation active dans le contexte courant. |
| `setCurrentConversation(?SynapseConversation $conversation): void` | Définit la conversation active. |
| `getHistoryArray(SynapseConversation $conversation): array` | Retourne l'historique formaté au format OpenAI canonical. |

---

## Chiffrement transparent

Si un `EncryptionServiceInterface` est configuré, le `ConversationManager` chiffre automatiquement les données sensibles (titre, contenu des messages) avant la persistance et les déchiffre à la lecture.

```php
// Le chiffrement est totalement transparent pour votre code
$conversation = $manager->createConversation($user, "Discussion confidentielle");
// → Le titre est chiffré en base de données

$history = $manager->getHistoryArray($conversation);
// → Le contenu des messages est déchiffré à la volée
```

---

## Exemple : Gestion manuelle d'une conversation

```php
namespace App\Service;

use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;

class ConversationService
{
    public function __construct(
        private ConversationManager $manager,
        private ChatService $chatService,
    ) {}

    public function startNewConversation(object $user, string $firstMessage): array
    {
        // Créer une nouvelle conversation
        $conversation = $this->manager->createConversation($user, null);

        // Envoyer le premier message en liant la conversation
        $result = $this->chatService->ask($firstMessage, [
            'conversation_id' => $conversation->getUlid(),
            'user_id'         => $user->getIdentifier(),
        ]);

        return $result;
    }

    public function deleteConversation(string $conversationId): void
    {
        $conversation = $this->manager->getCurrentConversation();
        if ($conversation && $conversation->getUlid() === $conversationId) {
            $this->manager->deleteConversation($conversation);
            $this->manager->setCurrentConversation(null);
        }
    }
}
```

---

## Voir aussi

- [Conversations & Persistance](../guides/rle-management.md) — guide complet d'utilisation
- [PermissionCheckerInterface](./contracts/permission-checker-interface.md) — contrôle des accès
- [EncryptionServiceInterface](./contracts/encryption-service-interface.md) — chiffrement des données
