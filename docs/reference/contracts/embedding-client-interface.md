# EmbeddingClientInterface

L'interface `EmbeddingClientInterface` est responsable de la conversion du langage humain en vecteurs mathématiques (Embeddings). C'est le traducteur nécessaire pour que le `VectorStore` puisse "comprendre" le sens des mots.

## 🛠 Pourquoi l'utiliser ?

*   **Standardisation** : Utiliser un format de vecteur unique pour tout votre projet.
*   **Abstraction** : Passer d'OpenAI à HuggingFace sans modifier votre pipeline de RAG.
*   **Efficacité** : Gérer les appels par lots (batching) pour transformer de gros volumes de texte rapidement.

---

## 📋 Résumé du Contrat

| Méthode | Entrée | Sortie | Rôle |
| :--- | :--- | :--- | :--- |
| `embedText(string $text)` | Texte brut | `array<float>` | Transforme une phrase en vecteur unique. |
| `embedBatch(array $texts)` | Liste de textes | `array<array>` | Optimise le traitement de plusieurs documents. |
| `getDimensions()` | - | `int` | Retourne la taille des vecteurs (ex: 1536). |

---

## 🚀 Exemple : Client d'embedding simulé

=== "DebugEmbeddingClient.php"

    ```php
    namespace App\Synapse\Embedding;

    use ArnaudMoncondhuy\SynapseCore\Contract\EmbeddingClientInterface;

    class DebugEmbeddingClient implements EmbeddingClientInterface
    {
        public function embedText(string $text): array
        {
            // Simule un vecteur de dimension 3
            return [0.12, 0.45, 0.89];
        }

        public function embedBatch(array $texts): array
        {
            return array_map([$this, 'embedText'], $texts);
        }

        public function getDimensions(): int { return 3; }
    }
    ```

---

## 💡 Conseils d'implémentation

*   **Normalisation** : La plupart des modèles (comme `text-embedding-3-small` d'OpenAI) retournent des vecteurs déjà normalisés. Si vous utilisez un modèle local, vérifiez si vous devez appliquer une normalisation L2.
*   **Limites de tokens** : Attention à la longueur du texte envoyé à `embedText`. Si le texte est trop long, il peut être nécessaire de le découper (Chunking) avant l'appel.

---


