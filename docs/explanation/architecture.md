# Architecture & Flux

Synapse Core repose sur un flux d'exécution séquentiel et hautement **événementiel**. Le pipeline de prompt est le cœur du système.

## Pipeline Prompt (5 Phases)

Le système de prompt est structuré en **5 phases événementielles** orchestrées par `PromptPipeline` :

### Phase 1 : BUILD (`PromptBuildEvent`)
Construction initiale du prompt système avec :
- System prompt de base (`SynapseConfig.systemPrompt`)
- Tone injecté (`SynapseTone.systemPrompt` si agent a un ton)
- Variables interpolées (`{user_name}`, `{context}`, etc.)

### Phase 2 : ENRICH (`PromptEnrichEvent`)
Enrichissement du contexte via subscribers :
- **`RagContextSubscriber`** : Injecte les chunks RAG pertinents (si agent a des sources RAG)
- **`MemoryContextSubscriber`** : Injecte les souvenirs vectoriels de l'utilisateur (score ≥ 0.7)
- **`MasterPromptSubscriber`** : Ajoute le master prompt global
- **`ContextBuilderSubscriber`** : Ajoute le contexte applicatif personnalisé

### Phase 3 : OPTIMIZE (`PromptOptimizeEvent`)
Optimisation et troncature :
- **`ContextTruncationSubscriber`** : Tronque l'historique et le contexte si dépassement de `maxTokens`
- Utilise une heuristique locale (règle simple : tokens ≈ chars/4)

### Phase 4 : FINALIZE (`PromptFinalizeEvent`)
Finalisation avant envoi au LLM (transformation dans le format du provider)

### Phase 5 : CAPTURE (`PromptCaptureEvent`)
Capture du prompt final pour le debug :
- **`DebugLogSubscriber`** : Enregistre le payload complet en `SynapseDebugLog`

---

## Flux Multi-tour avec Tool Calling

Une fois le prompt construit, le système entre dans une **boucle multi-tour** (max `maxTurns = 5`) :

```
Tour 1:
  ↓
  ChatService::ask() → Config Load
  ↓
  PromptPipeline (5 phases) → Prompt final
  ↓
  LlmClient::streamGenerateContent() → Streaming SSE
  ↓
  ChunkProcessor (parse SSE) → NormalizedChunk
  ↓
  Dispatch SynapseChunkReceivedEvent (répété chaque token)
  ↓
  Tool Call détecté ?
    NON → Fin (Dispatch SynapseGenerationCompletedEvent)
    OUI → Exécution ci-dessous

  ToolExecutor::execute(toolName, params) → Résultat
  ↓
  Dispatch SynapseToolCallCompletedEvent
  ↓
Tour 2+ : Retour au streaming avec résultat du tool en message assistant
```

---

## Composants Clés

| Composant | Rôle |
|-----------|------|
| **ChatService** | Point d'entrée principal (`ask()`, `resetConversation()`) |
| **ConfigProviderInterface** | Fournit la config runtime (via BDD) |
| **PromptPipeline** | Orchestre les 5 phases du prompt |
| **PromptBuilder** | Construit le prompt système initial |
| **Subscribers** | Enrichissent le prompt (RAG, mémoire, contexte app) |
| **LlmClient** | Envoie au provider (Gemini, OVH, etc.) |
| **ChunkProcessor** | Parse le streaming SSE en chunks normalisés |
| **MultiTurnExecutor** | Gère la boucle jusqu'à `maxTurns` |
| **ToolRegistry** | Registre des outils disponibles |
| **ToolExecutor** | Exécute les tool calls |

---

## Événements Dispatché

**Ordre de séquence** :

1. `SynapseGenerationStartedEvent` — Début global
2. *Phases du PromptPipeline* : `PromptBuildEvent`, `PromptEnrichEvent`, `PromptOptimizeEvent`, `PromptFinalizeEvent`, `PromptCaptureEvent`
3. `SynapseChunkReceivedEvent` — Répété pour chaque token (streaming)
4. `SynapseStatusChangedEvent` — Passage de thinking → generating (optionnel, si extended thinking)
5. `SynapseTokenStreamedEvent` — Chaque token individuel (granularité maximale)
6. `SynapseToolCallRequestedEvent` — Si outil demandé
7. `ToolExecutor` exécute → `SynapseToolCallCompletedEvent` — Résultat du tool
8. *(Retour étape 3 si plus de tool calls)*
9. `SynapseGenerationCompletedEvent` — Fin de génération textuelle
10. `SynapseExchangeCompletedEvent` — Fin technique (logs, debug)

Hors cycle : `SynapseEmbeddingCompletedEvent`, `SynapseSpendingLimitExceededEvent`, `SynapseFallbackActivatedEvent`

---

## Schéma Simplifié

```mermaid
graph TD
    User([Message Utilisateur]) --> ChatService[ChatService::ask]
    ChatService --> ConfigLoad[Chargement Config]
    ConfigLoad --> Pipeline["PromptPipeline<br/>(5 phases)"]

    Pipeline --> Build["BUILD<br/>PromptBuildEvent"]
    Build --> Enrich["ENRICH<br/>PromptEnrichEvent<br/>(RAG, Mémoire, Master)"]
    Enrich --> Optimize["OPTIMIZE<br/>PromptOptimizeEvent<br/>(Troncature)"]
    Optimize --> Finalize["FINALIZE<br/>PromptFinalizeEvent"]
    Finalize --> Capture["CAPTURE<br/>PromptCaptureEvent<br/>(Debug Log)"]

    Capture --> LlmClient[LlmClient::streamGenerateContent]
    LlmClient --> Streaming[Streaming SSE]
    Streaming --> Processor[ChunkProcessor]
    Processor --> DispatchChunk["Dispatch<br/>SynapseChunkReceivedEvent"]

    DispatchChunk --> Check{Tool Call ?}
    Check -- NON --> Complete["Dispatch<br/>SynapseGenerationCompletedEvent"]
    Check -- OUI --> ToolExec[ToolExecutor::execute]
    ToolExec --> DispatchTool["Dispatch<br/>SynapseToolCallCompletedEvent"]
    DispatchTool --> Streaming

    Complete --> ExchangeEvent["Dispatch<br/>SynapseExchangeCompletedEvent"]
    ExchangeEvent --> DB["Persistance BDD<br/>(SynapseMessage, etc.)"]
    DB --> UserResult([Réponse finale])
```

---

## Format Messages OpenAI Canonical

Internement, tous les messages sont dans le format OpenAI Chat Completions :

```php
$contents = [
    ['role' => 'system', 'content' => '...system prompt...'],
    ['role' => 'user', 'content' => '...message utilisateur...'],
    ['role' => 'assistant', 'content' => '...réponse modèle...', 'tool_calls' => [...]],
    ['role' => 'tool', 'tool_call_id' => '...', 'content' => '...résultat outil...'],
];
```

Chaque `LlmClient` traduit de/vers ce format (ex: GeminiClient adapte Gemini→OpenAI, OvhAiClient passe directement).
