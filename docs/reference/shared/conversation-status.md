# ConversationStatus (Enum)

L'énumération `ConversationStatus` définit les états possibles d'une conversation dans le cycle de vie de Synapse Core.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Shared\Enum\ConversationStatus
```

## Les 3 états

| État | Valeur | Signification |
|------|--------|---------------|
| `ACTIVE` | `'ACTIVE'` | Conversation en cours, modifiable. |
| `ARCHIVED` | `'ARCHIVED'` | Conversation masquée mais conservée (historique, lecture seule). |
| `DELETED` | `'DELETED'` | Conversation marquée pour suppression (soft delete, purge RGPD). |

## Méthodes

| Méthode | Rôle |
|---------|------|
| `visibleStatuses(): self[]` | Retourne les statuts visibles par l'utilisateur (`ACTIVE` et `ARCHIVED`). |
| `isVisible(): bool` | Indique si la conversation est visible (`true` si non supprimée). |
| `isEditable(): bool` | Indique si la conversation peut être modifiée (`true` uniquement si `ACTIVE`). |

## Utilisation

```php
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ConversationStatus;

// Vérifier si une conversation peut être éditée
if ($conversation->getStatus()->isEditable()) {
    // ...
}

// Filtrer les conversations visibles
$visible = ConversationStatus::visibleStatuses(); // [ACTIVE, ARCHIVED]
```

## Voir aussi

- [Conversations & Persistance](../../guides/rle-management.md) — gestion du cycle de vie
- [Commandes CLI](../cli.md#synapsepurge) — purge des conversations supprimées
