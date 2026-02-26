# Mise en œuvre du RAG (Mémoire Vectorielle)

Le RAG (*Retrieval-Augmented Generation*) permet à l'IA d'accéder à vos propres documents (PDF, Doc, Base de données) pour répondre de manière précise et sourcée, tout en s'affranchissant des limites de la fenêtre de contexte.

---

## 🚀 Étape 1 : Configuration du Vector Store

Synapse Core supporte plusieurs modes de stockage via l'option `vector_store.default`.

### Mode Doctrine / PostgreSQL (Recommandé)
Si vous utilisez PostgreSQL avec l'extension `pgvector`, Synapse utilisera des requêtes natives ultra-performantes.

```yaml
# config/packages/synapse.yaml
synapse:
    vector_store:
        default: doctrine
```

> [!IMPORTANT]
> N'oubliez pas d'exécuter `php bin/console doctrine:schema:update --force` pour créer la table `synapse_vector_memory`.

---

## 🧩 Étape 2 : Configuration du Chunking

Avant d'être mémorisés, les documents doivent être découpés. Vous pouvez régler ces paramètres dans l'**Admin Synapse** (Onglet Embeddings) :

1.  **Stratégie** : Choisissez `Recursive` pour un découpage qui respecte les paragraphes.
2.  **Taille des segments** : 1000 caractères est un bon compromis.
3.  **Overlap** : 200 caractères permettent de garder le fil entre deux segments.

---

## 🛠 Étape 3 : Alimenter la mémoire (RAG)

Pour ajouter des documents à la mémoire de l'IA, utilisez le `MemoryManager` (recommandé) ou directement le `VectorStoreInterface` :

```php
use ArnaudMoncondhuy\SynapseCore\Core\Memory\MemoryManager;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MemoryScope;

public function indexDocument(string $text, MemoryManager $memory, string $userId)
{
    $memory->remember(
        text: $text,
        scope: MemoryScope::USER,
        userId: $userId,
        sourceType: 'document'
    );
}
```

---

## 🧠 Comment Synapse utilise le RAG ?

Lors d'une conversation, Synapse Core :
1.  Vectorise la question de l'utilisateur.
2.  Recherche les **N** segments les plus proches dans le `VectorStore` (filtré par `user_id`).
3.  Injecte ces segments dans le prompt sous forme de "Contexte de référence".
4.  L'IA répond en se basant sur ces documents.

> [!TIP]
> Vous pouvez surveiller les requêtes RAG et les scores de similarité en activant le `debug_mode` dans la configuration.

---

## 🧬 Mémoire Sémantique Active (Human-in-the-loop)

En plus du RAG classique (documents pré-indexés), Synapse Core dispose d'un système de **mémoire conversationnelle** où le LLM peut proposer de retenir des informations importantes avec le consentement de l'utilisateur.

👉 Voir le guide dédié : [Mémoire Sémantique](semantic-memory.md)
