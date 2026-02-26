# VectorStoreInterface

L'interface `VectorStoreInterface` est le socle du système de RAG (Retrieval-Augmented Generation) dans Synapse Core. Elle définit comment stocker et rechercher des informations "vectorisées" (embeddings) pour donner une mémoire long-terme à votre IA.

## 🛠 Pourquoi l'utiliser ?

*   **Mémoire illimitée** : Permet à l'IA d'accéder à des milliers de documents sans saturer la fenêtre de contexte.
*   **Recherche sémantique** : Trouve des informations basées sur le **sens** plutôt que sur des simples mots-clés.
*   **Performances** : Délègue la recherche complexe à des moteurs spécialisés (Pinecone, Weaviate, PostgreSQL avec pgvector).

---

## 📋 Résumé du Contrat

| Méthode | Entrée | Sortie | Rôle |
| :--- | :--- | :--- | :--- |
| `saveMemory(array $vector, array $payload)` | Vecteurs + Métadonnées | `void` | Insère de nouvelles données dans la base vectorielle. |
| `searchSimilar(array $vector, int $limit, array $filters)` | Vecteur de recherche | `array` | Récupère les documents les plus proches sémantiquement. |

---

## 🚀 Exemple : Implémentation simplifiée en mémoire

    ```php
    namespace App\Synapse\Vector;

    use ArnaudMoncondhuy\SynapseCore\Contract\VectorStoreInterface;

    class InMemoryVectorStore implements VectorStoreInterface
    {
        private array $storage = [];

        public function saveMemory(array $vector, array $payload): void
        {
            $this->storage[] = [
                'vector'  => $vector,
                'payload' => $payload,
            ];
        }

        public function searchSimilar(array $vector, int $limit = 5, array $filters = []): array
        {
            // Ici, vous implémenteriez un calcul de similarité cosinus.
            return array_slice($this->storage, 0, $limit);
        }
    }
    ```

---

## 💡 Conseils d'implémentation

> [!TIP]
> **Métadonnées** : La méthode `add` reçoit un champ `metadata`. Utilisez-le pour stocker le texte source ou l'URL du document original. Cela facilitera l'affichage des sources par l'IA.

*   **Identifiants** : Gérez soigneusement les IDs pour éviter les doublons lors de la mise à jour de vos documents.
*   **Dimensionalité** : Assurez-vous que votre Store accepte la même dimension de vecteur que celle générée par votre `EmbeddingClientInterface` (ex: 1536 pour OpenAI).

---


