# Mémoire Sémantique "Human-in-the-loop"

La **Mémoire Sémantique** de Synapse Core permet à l'IA de retenir des informations importantes sur l'utilisateur au fil des conversations, avec son **consentement explicite**.

> [!IMPORTANT]
> Contrairement à un RAG classique (indexation de documents), la mémoire sémantique est **active** : c'est le LLM qui propose de mémoriser un fait, et l'utilisateur valide.

---

## Prérequis

- Un embedding provider configuré (voir l’interface d’administration, section **Synapse Admin**)
- Un Vector Store actif (voir [RAG & Mémoire Vectorielle](rag-memory.md))
- L'utilisateur doit implémenter `ConversationOwnerInterface` (pour l'isolation des données)

---

## Flux de fonctionnement

```
1. Utilisateur dit "Je suis allergique aux arachides"
2. LLM détecte l'information importante
3. LLM appelle l'outil propose_to_remember
4. 🧠 Toast discret dans le chat : "Retenir : X  [✓] [✕]"
5. Utilisateur clique ✓
6. POST /synapse/api/memory/confirm → MemoryManager::remember()
7. Prochaine conversation → souvenir injecté automatiquement dans le contexte
```

---

## Activation

L'outil `propose_to_remember` est **automatiquement disponible** dans le ToolRegistry dès l'installation du bundle, sans configuration supplémentaire.

Si vous souhaitez le **désactiver** (opt-out), vous pouvez le faire en overridant les `tools_override` dans vos options de chat :

```php
$chatService->ask($message, [
    'tools_override' => [], // Aucun outil
]);
```

---

## Le Service `MemoryManager`

Utilisez le `MemoryManager` pour manipuler la mémoire programmatiquement :

```php
use ArnaudMoncondhuy\SynapseCore\Memory\MemoryManager;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MemoryScope;

class MyService
{
    public function __construct(private MemoryManager $memoryManager) {}

    public function storeManually(string $userId): void
    {
        // Mémoriser un fait manuellement (portée utilisateur)
        $this->memoryManager->remember(
            text: "L'utilisateur parle couramment le japonais.",
            scope: MemoryScope::USER,
            userId: $userId,
            sourceType: 'manual'
        );
    }

    public function searchMemory(string $userId): void
    {
        // Recherche sémantique dans la mémoire de l'utilisateur
        $memories = $this->memoryManager->recall(
            query: "langues parlées",
            userId: $userId,
            limit: 3
        );

        foreach ($memories as $m) {
            echo $m['content'] . ' (score: ' . $m['score'] . ')';
        }
    }

    public function listAndDelete(string $userId): void
    {
        // Lister tous les souvenirs
        $memories = $this->memoryManager->listForUser($userId);

        // Supprimer un souvenir par ID
        $this->memoryManager->forget($memories[0]->getId(), $userId);
    }
}
```

---

## Portées (`MemoryScope`)

| Valeur | Description |
| :--- | :--- |
| `MemoryScope::USER` | Souvenir permanent, disponible dans **toutes** les conversations de l'utilisateur |
| `MemoryScope::CONVERSATION` | Souvenir éphémère, lié à une **conversation spécifique** |

---

## API REST pour le Frontend

Le bundle expose des endpoints REST pour gérer les souvenirs depuis votre interface :

| Route | Méthode | Description |
| :--- | :--- | :--- |
| `/synapse/api/memory/confirm` | `POST` | Valider une proposition de mémorisation |
| `/synapse/api/memory/reject` | `POST` | Refuser une proposition |
| `/synapse/api/memory` | `GET` | Lister les souvenirs de l'utilisateur connecté |
| `/synapse/api/memory/{id}` | `DELETE` | Supprimer un souvenir (RGPD) |

### Exemple : confirmer un souvenir

```javascript
await fetch('/synapse/api/memory/confirm', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        fact: "L'utilisateur est allergique aux arachides",
        category: 'constraint',
        scope: 'user',
        conversation_id: '...' // optionnel
    })
});
```

---

## Types de sources (`source_type`)

| Valeur | Description |
| :--- | :--- |
| `fact` | Extrait d'une conversation (via `ProposeMemoryTool`) |
| `manual` | Saisi programmatiquement par l'application |
| `document` | *(Prévu)* Issu d'un fichier uploadé (PDF, etc.) |

---

## Sécurité & Isolation des données

> [!WARNING]
> Le filtrage par `user_id` est imposé **au niveau SQL** dans le `DoctrineVectorStore`, pas en post-traitement PHP. Cela garantit qu'une faille applicative ne peut pas exposer les souvenirs d'un utilisateur à un autre.

L'injection des souvenirs dans le contexte du LLM est gérée par le `MemoryContextSubscriber` (priorité 50), qui s'exécute **après** la construction du prompt principal. Seuls les souvenirs avec un **score de similarité ≥ 0.7** sont injectés.

---

## Injection automatique dans le contexte

Le `MemoryContextSubscriber` s'active automatiquement si :
1. Un utilisateur est connecté et implémente `ConversationOwnerInterface`.
2. Un embedding provider est configuré.

Les souvenirs pertinents sont injectés sous forme de bloc `system` discret, invisible dans l'historique de la conversation mais présent dans le prompt envoyé au LLM :

```
Informations connues sur l'utilisateur (mémorisées lors des conversations précédentes) :
- L'utilisateur est allergique aux arachides
- L'utilisateur préfère les réponses concises
```

---

## Limitations actuelles

- Pas de gestion des **contradictions** : si l'utilisateur contredit un ancien souvenir, les deux coexistent. Une future version ajoutera `propose_to_update` et `propose_to_forget`.
- L'upload de **documents** (PDF, etc.) n'est pas encore disponible via l'UI.
