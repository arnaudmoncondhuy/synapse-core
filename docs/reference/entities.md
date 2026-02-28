# Entités Doctrine

Synapse Core utilise des `MappedSuperclass` que vous devez étendre dans votre application pour activer la persistance.

## Entités principales

- **SynapseConversation** : Stocke les métadonnées de la discussion (titre, propriétaire, date).
- **SynapseMessage** : Stocke le contenu des échanges (rôle, contenu texte, calls outils).

## Entités de configuration

- **SynapsePreset** : Configuration technique d’un modèle (température, outils, etc.).
- **SynapseProvider** : Credentials chiffrés pour les fournisseurs (Gemini, OpenAI).
- **SynapseModel** : Métadonnées et tarification des modèles LLM.
- **SynapseMission** : Configuration d’agent (system prompt, preset, ton).
- **SynapseTone** : Styles de réponse (emoji, instructions de ton).
- **SynapseConfig** : Paramètres globaux (Rétention, Langue, Chunking, Vector Store actif).

## Entités de suivi & Quotas

- **SynapseTokenUsage** : Historique exhaustif de la consommation de jetons et des coûts.
- **SynapseSpendingLimit** : Plafonds de dépense par utilisateur, mission ou preset.


## Entités RAG & Mémoire Sémantique

### `SynapseVectorMemory`

Stocke les vecteurs d'embeddings et leurs contenus pour la recherche sémantique et la mémoire conversationnelle.

| Colonne | Type | Description |
| :--- | :--- | :--- |
| `embedding` | `json` | Vecteur numérique généré par le modèle d'embedding |
| `payload` | `json` | Métadonnées brutes (texte, source, ids) |
| `content` | `text` | Texte brut dénormalisé (pour affichage dans le Privacy Dashboard) |
| `user_id` | `string(255)` | Propriétaire du souvenir — compatible UUID et entier |
| `scope` | `string(20)` | `user` (permanent) ou `conversation` (éphémère) |
| `conversation_id` | `string(255)` | Lien vers la conversation d'origine (optionnel) |
| `source_type` | `string(20)` | `fact`, `document`, `manual` — prépare le support d'upload de fichiers |
| `created_at` | `datetime_immutable` | Date de création du souvenir |

> [!NOTE]
> Reportez-vous au guide [Mémoire Sémantique](../guides/semantic-memory.md) pour l'utilisation et au guide [Persistance](../guides/rle-management.md) pour les détails Doctrine.
