# MemoryScope (Enum)

`MemoryScope` définit la portée d'un souvenir dans le système de mémoire sémantique de Synapse Core.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Shared\Enum\MemoryScope
```

## Valeurs

| Valeur | Chaîne | Description |
|--------|--------|-------------|
| `MemoryScope::USER` | `"user"` | Souvenir **permanent**, disponible dans toutes les conversations de l'utilisateur. |
| `MemoryScope::CONVERSATION` | `"conversation"` | Souvenir **éphémère**, lié à une conversation spécifique uniquement. |

## Utilisation

```php
use ArnaudMoncondhuy\SynapseCore\Memory\MemoryManager;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MemoryScope;

// Souvenir permanent (toutes les conversations)
$memory->remember(
    text: "L'utilisateur préfère le vouvoiement.",
    scope: MemoryScope::USER,
    userId: $userId,
);

// Souvenir de session (une seule conversation)
$memory->remember(
    text: "L'utilisateur cherche un vol Paris-Tokyo.",
    scope: MemoryScope::CONVERSATION,
    userId: $userId,
    conversationId: $conversationId,
);
```

## Voir aussi

- [Guide Mémoire Sémantique](../../guides/semantic-memory.md)
- [Entité SynapseVectorMemory](../entities.md#synapsevectormemory)
