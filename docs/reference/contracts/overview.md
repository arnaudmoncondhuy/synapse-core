# Vue d'ensemble des Contrats (Interfaces)

Synapse Core est conçu comme un **kit de construction**. Chaque brique majeure est définie par une interface (un "contrat") que vous pouvez réimplémenter pour adapter le bundle à vos besoins exacts.

!!! tip "Pas besoin de tout implémenter"
    Synapse Core arrive avec des implémentations par défaut pour la plupart de ces briques. Vous ne remplacez que ce dont vous avez réellement besoin.

---

## Cœur & Orchestration

| Interface | Rôle principal |
|-----------|----------------|
| [**LlmClientInterface**](./llm-client-interface.md) | **Le Moteur.** Connecte Synapse à OpenAI, Gemini, Ollama, etc. |
| [**AiToolInterface**](./ai-tool-interface.md) | **L'Action.** Permet à l'IA d'appeler votre code PHP (Function Calling). |
| [**StatusAwareToolInterface**](./status-aware-tool-interface.md) | **Le Feedback.** Message personnalisé dans l'UI pendant l'exécution d'un outil. |
| [**AgentInterface**](./agent-interface.md) | **L'Orchestrateur.** Agent programmatique pour des tâches complexes multi-étapes. |

## RAG & Mémoire (Long-terme)

| Interface | Rôle principal |
|-----------|----------------|
| [**VectorStoreInterface**](./vector-store-interface.md) | **Le Stockage.** Gère les documents vectorisés (PostgreSQL pgvector, Pinecone…). |
| [**EmbeddingClientInterface**](./embedding-client-interface.md) | **Le Traducteur.** Transforme le texte en vecteurs mathématiques. |
| [**TextSplitterInterface**](./text-splitter-interface.md) | **Le Découpeur.** Divise les documents en chunks optimisés pour le RAG. |
| [**RagSourceProviderInterface**](./rag-source-provider-interface.md) | **La Source.** Déclare une source de documents indexables (Drive, Notion, API…). |

## Sécurité & Conformité

| Interface | Rôle principal |
|-----------|----------------|
| [**EncryptionServiceInterface**](./encryption-service-interface.md) | **La Vie Privée.** Chiffre vos messages et credentials en base de données. |
| [**PermissionCheckerInterface**](./permission-checker-interface.md) | **Le Gardien.** Contrôle qui peut lire, modifier ou utiliser quoi. |
| [**RetentionPolicyInterface**](./retention-policy-interface.md) | **Le RGPD.** Définit les règles de purge automatique. |

## Personnalisation du Flux

| Interface | Rôle principal |
|-----------|----------------|
| [**ContextProviderInterface**](./context-provider-interface.md) | **L'Injection.** Ajoute un prompt système et des données dynamiques à chaque échange. |
| [**ConfigProviderInterface**](./config-provider-interface.md) | **Le Réglage.** Fournit la configuration runtime (`SynapseRuntimeConfig`) aux clients LLM. |
| [**ConversationOwnerInterface**](./conversation-owner-interface.md) | **Le Propriétaire.** Identifie l'entité utilisateur propriétaire d'une conversation. |
| [**MessageFormatterInterface**](./message-formatter-interface.md) | **Le Normalisateur.** Convertit les entités `SynapseMessage` ↔ format OpenAI canonical. |
| [**SynapseDebugLoggerInterface**](./synapse-debug-logger-interface.md) | **Le Débogueur.** Enregistre les payloads bruts pour l'analyse d'erreurs et de qualité. |
