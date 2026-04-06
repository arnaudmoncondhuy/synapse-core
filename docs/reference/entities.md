# Entités Doctrine

Synapse Core utilise des entités Doctrine `MappedSuperclass` que vous étendez dans votre application pour activer la persistance.

## Entités à étendre (obligatoires pour la persistance)

- **`SynapseConversation`** : Stocke les métadonnées de la discussion (titre, propriétaire, date, statut).
- **`SynapseMessage`** : Stocke le contenu des échanges (rôle, contenu texte, calls outils).

## Entités de configuration

- **`SynapseModelPreset`** : Configuration technique d'un modèle (température, streaming, max_tokens, options provider, etc.). Supporte le [pattern sandbox](#pattern-sandbox).
- **`SynapseProvider`** : Credentials chiffrés pour les fournisseurs (Gemini, OpenAI, OVH, etc.).
- **`SynapseModel`** : Métadonnées et tarification des modèles LLM.
- **`SynapseAgent`** : Configuration d'agent (system prompt, preset, ton, outils autorisés, sources RAG, accès contrôlé). Supporte le [pattern sandbox](#pattern-sandbox).
- **`SynapseTone`** : Styles de réponse (emoji, instructions de ton).
- **`SynapseConfig`** : Paramètres globaux (rétention, langue, chunking, vector store actif).

## Entités Workflow

### `SynapseWorkflow`

Définition d'un pipeline multi-agents. Supporte le [pattern sandbox](#pattern-sandbox).

La définition JSON suit le format pivot :

```json
{
  "version": 1,
  "steps": [
    {
      "name": "step1",
      "agent_name": "mon_agent",
      "input_mapping": {},
      "output_key": "resultat"
    }
  ],
  "outputs": {
    "final": "$.steps.step1.output.answer"
  }
}
```

Toute modification du champ `definition` incrémente automatiquement `version`, garantissant la traçabilité historique.

### `SynapseWorkflowRun`

Instance d'exécution d'un `SynapseWorkflow`. Un run est mutable pendant son exécution (statut, step courant, timings) et devient immuable une fois terminé.

| Colonne | Type | Description |
|---------|------|-------------|
| `workflowRunId` | `string(36)` | UUID logique propagé dans `AgentContext` et référencé par les `SynapseDebugLog` |
| `workflowKey` | `string(100)` | Clé dénormalisée pour survivre à la suppression de la définition |
| `workflowVersion` | `integer` | Version de la définition au moment du run |
| `status` | `enum` | `running`, `completed`, `failed` |
| `currentStepIndex` | `integer` | Index de la dernière étape exécutée |
| `stepsCount` | `integer` | Nombre total d'étapes |
| `input` | `json` | Données d'entrée |
| `output` | `json` | Sorties produites |
| `errorMessage` | `text` | Message d'erreur si `failed` |
| `totalTokens` | `integer` | Tokens totaux consommés |
| `durationSeconds` | `float` | Durée en secondes |
| `startedAt` | `datetime_immutable` | Début |
| `completedAt` | `datetime_immutable` | Fin (nullable si encore en cours) |

La relation vers `SynapseWorkflow` est `nullable` avec `onDelete: SET NULL` : les runs historiques survivent à la suppression de la définition grâce au champ `workflowKey` dénormalisé.

## Pattern Sandbox

Le pattern sandbox est utilisé par les [outils MCP](../../../mcp/docs/tools/sandbox.md) pour créer des entités temporaires de test.

### Entités concernées

- `SynapseAgent` — champ `isSandbox boolean DEFAULT false`
- `SynapseWorkflow` — champ `isSandbox boolean DEFAULT false`
- `SynapseModelPreset` — champ `isSandbox boolean DEFAULT false`

### Règles de filtrage

| Méthode | Filtre sandbox |
|---------|----------------|
| `findAllActive()` | `isSandbox = false` — exclut les sandbox |
| `findAllOrdered()` | `isSandbox = false` — exclut les sandbox |
| `findAllPresets()` | `isSandbox = false` — exclut les sandbox |
| `findByKey($key)` | Aucun filtre — inclut les sandbox |
| `findActive()` | Aucun filtre — inclut les sandbox |
| `findActiveByKey($key)` | Aucun filtre — inclut les sandbox |
| `findSandbox()` | `isSandbox = true` — retourne uniquement les sandbox |

Les entités sandbox sont **invisibles dans l'admin et le chat** (les listings utilisent `findAllActive/Ordered/Presets`) mais **résolvables pour l'exécution** (le moteur utilise `findByKey` / `findActiveByKey`).

### Méthode de cleanup

`SynapseWorkflowRunRepository::deleteByWorkflowKeys(array $keys): int` supprime en lot les runs associés à une liste de workflow keys — utilisé par `cleanup_sandbox` avant de supprimer les workflows eux-mêmes.

## Entités de suivi & comptabilité

### `SynapseLlmCall`

Enregistrement atomique d'un appel LLM. Table centrale du token accounting.

Chaque appel LLM — qu'il provienne du chat, d'une génération de titre, d'une tâche automatisée ou d'un agent — produit une ligne dans cette table. Les tables `synapse_conversation` et `synapse_message` référencent `synapse_llm_call` via `llm_call_id`.

| Colonne | Type | Description |
|---------|------|-------------|
| `callId` | `string(36)` | UUID v4 unique de l'appel |
| `module` | `string` | Module source (ex: `chat`, `title`, `rag`) |
| `model` | `string` | Identifiant du modèle utilisé |
| `provider` | `string` | Provider utilisé (ex: `gemini`) |
| `promptTokens` | `integer` | Tokens en entrée |
| `completionTokens` | `integer` | Tokens générés |
| `totalTokens` | `integer` | Total tokens |
| `costModel` | `float` | Coût calculé dans la devise du provider |
| `costReference` | `float` | Coût converti en devise de référence |
| `presetId` | `?integer` | ID du preset utilisé |
| `agentId` | `?integer` | ID de l'agent utilisé |
| `userId` | `?string` | Identifiant de l'utilisateur |
| `conversationId` | `?string` | ULID de la conversation |
| `createdAt` | `datetime_immutable` | Date de l'appel |

### `SynapseSpendingLimit`

Plafonds de dépense par utilisateur, agent ou preset.

### `SynapseSpendingLimitLog`

Historique des dépassements de limite de dépense.

| Colonne | Type | Description |
|---------|------|-------------|
| `userId` | `string(255)` | Utilisateur qui a déclenché le dépassement |
| `scope` | `enum` | `user`, `preset`, `agent` |
| `scopeId` | `string(255)` | Identifiant de la ressource |
| `period` | `enum` | `calendar_day`, `calendar_month`, `sliding_day`, `sliding_month` |
| `limitAmount` | `float` | Montant du plafond |
| `consumption` | `float` | Consommation avant l'appel dépassant |
| `estimatedCost` | `float` | Coût estimé de l'appel déclencheur |
| `overrunAmount` | `float` | Montant du dépassement |
| `currency` | `string(3)` | Devise (EUR, USD, etc.) |
| `exceededAt` | `datetime_immutable` | Date/heure du dépassement |

## Entités RAG & Mémoire Sémantique

### `SynapseRagSource`

Représente une source de documents indexés (Google Drive, Notion, fichiers locaux, etc.).

| Colonne | Type | Description |
|---------|------|-------------|
| `slug` | `string(255)` | Identifiant unique (ex: `lycee_intranet`) |
| `name` | `string(255)` | Nom d'affichage |
| `description` | `text` | Description longue |
| `isActive` | `boolean` | Activé ou désactivé |
| `documentCount` | `integer` | Nombre total de documents indexés |
| `lastIndexedAt` | `datetime_immutable` | Dernière réindexation complète |
| `indexingStatus` | `string(50)` | `pending`, `indexing`, `done`, `failed` |
| `lastError` | `text` | Message d'erreur si indexation échouée |
| `totalFiles` | `integer` | Nombre total de fichiers traités |
| `processedFiles` | `integer` | Fichiers traités avec succès (suivi progression) |

### `SynapseRagDocument`

Chunk vectorisé d'un document RAG.

| Colonne | Type | Description |
|---------|------|-------------|
| `source` | FK | Lien vers `SynapseRagSource` (CASCADE DELETE) |
| `content` | `text` | Contenu du chunk |
| `embedding` | `json` | Vecteur numérique d'embedding (float array) |
| `metadata` | `json` | Métadonnées : `source_name`, `file_path`, `page_number`, etc. |
| `chunkIndex` | `integer` | Numéro du chunk dans le document original |
| `totalChunks` | `integer` | Nombre total de chunks du document |
| `sourceIdentifier` | `string(255)` | Identifiant unique dans la source (ex: Google Drive file ID) |

### `SynapseMessageAttachment`

Fichiers téléchargés attachés aux messages (images, documents, etc.).

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | UUID | Clé primaire |
| `message` | FK | Lien vers `SynapseMessage` |
| `mimeType` | `string(100)` | Type MIME (ex: `image/png`) |
| `filePath` | `string(500)` | Chemin disque (ex: `var/uploads/uuid.png`) |
| `createdAt` | `datetime_immutable` | Date du téléchargement |

### `SynapseVectorMemory`

Stocke les vecteurs d'embeddings pour la recherche sémantique et la mémoire conversationnelle.

| Colonne | Type | Description |
|---------|------|-------------|
| `embedding` | `json` | Vecteur numérique généré par le modèle d'embedding |
| `payload` | `json` | Métadonnées brutes (texte, source, ids) |
| `content` | `text` | Texte brut dénormalisé (affichage dans le Privacy Dashboard) |
| `user_id` | `string(255)` | Propriétaire du souvenir — compatible UUID et entier |
| `scope` | `string(20)` | `user` (permanent) ou `conversation` (éphémère) |
| `conversation_id` | `string(255)` | Lien vers la conversation d'origine (optionnel) |
| `source_type` | `string(20)` | `fact`, `document`, `manual` |
| `created_at` | `datetime_immutable` | Date de création du souvenir |

### `SynapseDebugLog`

Enregistrement des échanges complets pour le mode debug. Stocke le payload brut des requêtes et réponses API, les timings, les métadonnées du modèle.

| Colonne | Type | Description |
|---------|------|-------------|
| `debugId` | `string(50)` | Identifiant unique de cet appel LLM |
| `module` | `string(100)` | Module source (ex: `chat`, `agent`, `rag`) — dénormalisé |
| `action` | `string(100)` | Action précise (ex: `chat_turn`, `agent_call`) — dénormalisé |
| `model` | `string(100)` | Modèle utilisé — dénormalisé |
| `totalTokens` | `integer` | Tokens totaux — dénormalisé |
| `parentRunId` | `string(36)` | `agentRunId` de l'exécution parente (null = appel racine) |
| `agentRunId` | `string(36)` | UUID de l'exécution logique de l'agent (partagé si plusieurs appels LLM pour un même agent) |
| `depth` | `smallint` | Profondeur d'imbrication (0 = racine) |
| `origin` | `string(20)` | `direct`, `code`, `config`, `ephemeral`, `workflow` |
| `workflowRunId` | `string(36)` | UUID du workflow englobant (null si hors workflow) |
| `data` | `json` | Payload complet : `preset_config`, `history`, `usage`, `turns`, `safety_ratings`, `raw_request_body` |
| `createdAt` | `datetime_immutable` | Date de l'appel |

### Requêtes racines (`findRoots`)

`SynapseDebugLogRepository::findRoots()` retourne les entrées de "premier niveau" visibles dans l'admin. Sont considérés comme racines :

- Appels sans parent (`parentRunId IS NULL`) — appels directs (chat, MCP, etc.)
- Appels `origin = 'workflow'` à `depth = 1` — étapes de workflow (elles ont un `parentRunId` mais restent des entrées de premier niveau dans la vue admin)

!!! note "Voir aussi"
    - [Mémoire Sémantique](../guides/semantic-memory.md) — utilisation de `SynapseVectorMemory`
    - [Persistance](../guides/rle-management.md) — détails Doctrine pour `SynapseConversation` / `SynapseMessage`
    - [Token Accounting](../guides/configuration.md#suivi-de-tokens--coûts-token_tracking) — configuration du suivi via `SynapseLlmCall`
