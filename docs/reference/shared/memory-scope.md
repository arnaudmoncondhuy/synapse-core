# MemoryScope

`MemoryScope` est un Enum définissant la portée d'un souvenir dans le système de mémoire sémantique.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Shared\Enum\MemoryScope
```

## Valeurs

| Valeur | Chaîne | Description |
| :--- | :--- | :--- |
| `MemoryScope::USER` | `"user"` | Souvenir **permanent**, disponible dans toutes les conversations de l'utilisateur |
| `MemoryScope::CONVERSATION` | `"conversation"` | Souvenir **éphémère**, lié à une conversation spécifique uniquement |

## Utilisation

```php
use ArnaudMoncondhuy\SynapseCore\Core\Memory\MemoryManager;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MemoryScope;

// Souvenir permanent (toutes les conversations)
$memory->remember("L'utilisateur préfère le vouvoiement.", MemoryScope::USER, $userId);

// Souvenir de session (une seule conversation)
$memory->remember("L'utilisateur cherche un vol Paris-Tokyo.", MemoryScope::CONVERSATION, $userId, $conversationId);
```

## Voir aussi

- [Guide Mémoire Sémantique](../../guides/semantic-memory.md)
- [MemoryManager](../../guides/semantic-memory.md#le-service-memorymanager)
