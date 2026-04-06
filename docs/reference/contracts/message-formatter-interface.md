# MessageFormatterInterface

L'interface `MessageFormatterInterface` gère la conversion bidirectionnelle entre les entités Doctrine (`SynapseMessage`) et le format OpenAI canonical utilisé en interne et par les clients LLM.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Contract\MessageFormatterInterface
```

## Contrat complet

```php
interface MessageFormatterInterface
{
    public function entitiesToApiFormat(iterable $entities): array;
    public function apiFormatToEntities(array $messages, SynapseConversation $conversation): array;
}
```

## Méthodes

| Méthode | Rôle |
|---------|------|
| `entitiesToApiFormat(iterable $entities): array` | Convertit des entités `SynapseMessage` vers le format OpenAI canonical (pour l'envoi au LLM). |
| `apiFormatToEntities(array $messages, SynapseConversation $conversation): array` | Transforme des messages au format API en objets entités prêts à la persistance. |

---

## Cas d'usage

Synapse Core inclut une implémentation par défaut conforme au standard OpenAI. Vous n'avez besoin d'implémenter cette interface que si vous avez des besoins de transformation très spécifiques :

- Filtrer ou modifier le contenu des messages avant envoi au LLM
- Ajouter des métadonnées supplémentaires pour le traitement par le LLM
- Adapter le format pour un provider avec des contraintes particulières

!!! tip "Conseil"
    N'implémentez cette interface que si nécessaire. La grande majorité des projets n'ont pas à la personnaliser.

---

## Format de sortie de `entitiesToApiFormat()`

```php
// Exemple de sortie (format OpenAI canonical)
[
    ['role' => 'user',      'content' => 'Bonjour !'],
    ['role' => 'assistant', 'content' => 'Bonjour, comment puis-je vous aider ?'],
    ['role' => 'user',      'content' => 'Quelle heure est-il ?'],
]
```

---

## Voir aussi

- [Format OpenAI Canonical](../../explanation/architecture.md#format-messages-openai-canonical) — description du format interne
- [LlmClientInterface](./llm-client-interface.md) — utilise ce format en entrée
