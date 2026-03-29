# Entités Doctrine

Synapse Core utilise des `MappedSuperclass` que vous devez étendre dans votre application pour activer la persistance.

## Entités principales

- **SynapseConversation** : Stocke les métadonnées de la discussion (titre, propriétaire, date).
- **SynapseMessage** : Stocke le contenu des échanges (rôle, contenu texte, calls outils).

## Entités de configuration

- **SynapseModelPreset** : Configuration technique d’un modèle (température, outils, etc.).
- **SynapseProvider** : Credentials chiffrés pour les fournisseurs (Gemini, OpenAI).
- **SynapseModel** : Métadonnées et tarification des modèles LLM.
- **SynapseAgent** : Configuration d’agent (system prompt, preset, ton, outils autorisés, sources RAG, accès contrôlé).
- **SynapseTone** : Styles de réponse (emoji, instructions de ton).
- **SynapseConfig** : Paramètres globaux (Rétention, Langue, Chunking, Vector Store actif).

## Entités de suivi & Quotas

- **SynapseTokenUsage** : Historique exhaustif de la consommation de jetons et des coûts.
- **SynapseSpendingLimit** : Plafonds de dépense par utilisateur, mission ou preset.


## Entités RAG & Mémoire Sémantique

### Sources RAG

#### `SynapseRagSource`

Représente une source de documents indexés (Google Drive, Notion, fichiers locaux, etc.).

| Colonne | Type | Description |
| :--- | :--- | :--- |
| `slug` | `string(255)` | Identifiant unique (ex: `lycee_intranet`) |
| `name` | `string(255)` | Nom d'affichage |
| `description` | `text` | Description longue |
| `isActive` | `boolean` | Activé ou désactivé |
| `documentCount` | `integer` | Nombre total de documents indexés |
| `lastIndexedAt` | `datetime_immutable` | Dernière réindexation complète |
| `indexingStatus` | `string(50)` | `pending`, `indexing`, `done`, `failed` |
| `lastError` | `text` | Message d'erreur si indexation échouée |
| `totalFiles` | `integer` | Nombre total de fichiers traités |
| `processedFiles` | `integer` | Nombre de fichiers avec succès (suivi progression) |

#### `SynapseRagDocument`

Chunk vectorisé d'un document RAG.

| Colonne | Type | Description |
| :--- | :--- | :--- |
| `source` | FK | Lien vers `SynapseRagSource` (CASCADE DELETE) |
| `content` | `text` | Contenu du chunk |
| `embedding` | `json` | Vecteur numérique d'embedding (float array) |
| `metadata` | `json` | Métadonnées : `source_name`, `file_path`, `page_number`, etc. |
| `chunkIndex` | `integer` | Numéro du chunk dans le document original |
| `totalChunks` | `integer` | Nombre total de chunks du document |
| `sourceIdentifier` | `string(255)` | Identifiant unique dans la source (ex: Google Drive file ID) |

### Pièces jointes

#### `SynapseMessageAttachment`

Fichiers téléchargés attachés aux messages (images, documents, etc.).

| Colonne | Type | Description |
| :--- | :--- | :--- |
| `id` | UUID | Clé primaire |
| `message` | FK | Lien vers `SynapseMessage` |
| `mimeType` | `string(100)` | Type MIME (ex: `image/png`) |
| `filePath` | `string(500)` | Chemin disque (ex: `var/uploads/uuid.png`) |
| `createdAt` | `datetime_immutable` | Date du téléchargement |

### Mémoire vectorielle

#### `SynapseVectorMemory`

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

## Suivi & Quotas (Compléments)

### `SynapseSpendingLimitLog`

Historique des dépassements de limite de dépense.

| Colonne | Type | Description |
| :--- | :--- | :--- |
| `userId` | `string(255)` | Utilisateur qui a déclenché le dépassement |
| `scope` | `enum` | `user`, `preset`, `agent` — le type de limite |
| `scopeId` | `string(255)` | Identifiant de la ressource (user ID, preset ID, agent slug) |
| `period` | `enum` | `calendar_day`, `calendar_month`, `sliding_day`, `sliding_month` |
| `limitAmount` | `float` | Montant du plafond |
| `consumption` | `float` | Consommation avant l'appel dépassant |
| `estimatedCost` | `float` | Coût estimé de l'appel qui a déclenché |
| `overrunAmount` | `float` | Montant du dépassement (`estimatedCost` - `(limitAmount - consumption)`) |
| `currency` | `string(3)` | Devise (EUR, USD, GBP, etc.) |
| `exceededAt` | `datetime_immutable` | Date/heure du dépassement |
