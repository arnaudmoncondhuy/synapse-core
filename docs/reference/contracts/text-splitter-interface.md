# TextSplitterInterface

L'interface `TextSplitterInterface` définit comment découper de longs documents en segments plus petits (chunks) avant de les transformer en vecteurs. C'est une étape cruciale pour le RAG (Retrieval-Augmented Generation).

## 🛠 Pourquoi l'utiliser ?

*   **Limites de Tokens** : Les modèles d'embedding ont une taille d'entrée limitée.
*   **Précision du RAG** : Des segments trop longs diluent l'information ; des segments trop courts perdent le contexte.
*   **Chevauchement (Overlap)** : Permet de conserver le contexte entre deux segments consécutifs.

---

## 📋 Résumé du Contrat

| Méthode | Entrée | Sortie | Rôle |
| :--- | :--- | :--- | :--- |
| `splitText(string $text, int $chunkSize, int $chunkOverlap)` | Texte source + réglages | `string[]` | Découpe le texte en un tableau de segments. |

---

## 🚀 Stratégies disponibles

Synapse Core propose deux implémentations natives :

### 1. RecursiveTextSplitter (Recommandé)
Tente de découper le texte intelligemment en utilisant une liste de séparateurs par ordre de priorité :
1. Doubles sauts de ligne (paragraphes)
2. Sauts de ligne simples
3. Espaces
4. Caractères individuels (en dernier recours)

Cela garantit que les paragraphes restent soudés autant que possible, préservant la cohérence sémantique.

### 2. FixedSizeTextSplitter
Découpe brutalement tous les X caractères. Plus rapide mais peut couper au milieu d'un mot ou d'une phrase importante.

---

## ⚙️ Configuration

Le splitter est piloté par le [ChunkingService](../../guides/configuration.md) qui récupère les réglages (taille, overlap) depuis la configuration globale de Synapse.

```php
// Exemple d'utilisation manuelle
$chunks = $splitter->splitText($grosFichier, 1000, 200);
```
