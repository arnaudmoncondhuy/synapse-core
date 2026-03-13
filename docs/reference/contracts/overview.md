# Vue d'ensemble des Contrats (Interfaces)

Synapse Core est conçu comme un **kit de construction**. Chaque brique majeure est définie par une interface (un "contrat") que vous pouvez réimplémenter pour adapter le bundle à vos besoins exacts.

---

## 🏗 Les piliers du système

Cliquez sur une interface pour découvrir son guide d'implémentation premium.

### 🧠 Cœur & Orchestration
| Interface | Rôle principal |
| :--- | :--- |
| [**LlmClientInterface**](../contracts/llm-client-interface.md) | **Le Moteur.** Connecte Synapse à OpenAI, Gemini, Ollama, etc. |
| [**AiToolInterface**](../contracts/ai-tool-interface.md) | **L'Action.** Permet à l'IA d'appeler votre code PHP (Function Calling). |
| [**AgentInterface**](../contracts/agent-interface.md) | **Le Cerveau.** Définit une personnalité et des outils spécifiques. |

### 💾 RAG & Mémoire (Mémoire long-terme)
| Interface | Rôle principal |
| :--- | :--- |
| [**VectorStoreInterface**](../contracts/vector-store-interface.md) | **Le Stockage.** Gère les documents vectorisés (PostgreSQL, Pinecone). |
| [**EmbeddingClientInterface**](../contracts/embedding-client-interface.md) | **Le Traducteur.** Transforme le texte en vecteurs mathématiques. |
| [**TextSplitterInterface**](../contracts/text-splitter-interface.md) | **Le Découpeur.** Divise les documents en chunks optimisés pour le RAG. |

### 🛡 Sécurité & Conformité
| Interface | Rôle principal |
| :--- | :--- |
| [**EncryptionService**](../contracts/encryption-service-interface.md) | **La Vie Privée.** Chiffre vos messages en base de données. |
| [**PermissionChecker**](../contracts/permission-checker-interface.md) | **Le Gardien.** Contrôle qui peut lire ou modifier quel chat. |
| [**RetentionPolicy**](../contracts/retention-policy-interface.md) | **Le RGPD.** Définit les règles de purge automatique. |

### ⚙️ Personnalisation du Flux
| Interface | Rôle principal |
| :--- | :--- |
| [**ContextProvider**](../contracts/context-provider-interface.md) | **L'Injection.** Ajoute des données dynamiques au prompt système. |
| [**ConfigProvider**](../contracts/config-provider-interface.md) | **Le Réglage.** Ajuste la température et les filtres dynamiquement. |
| **MessageFormatterInterface** | **Le Normalisateur.** Convertit les entités `SynapseMessage` vers le format OpenAI canonique et vice-versa. |
| **SynapseDebugLoggerInterface** | **Le Débogueur.** Enregistre les payloads bruts (requêtes/réponses API) pour l'analyse d'erreurs et la qualité. Méthodes : `logExchange(debugId, metadata, rawPayload)`, `findByDebugId(debugId)`. |

---

> [!TIP]
> **Pas besoin de tout implémenter !** Synapse Core arrive avec des implémentations par défaut pour la plupart de ces briques. Vous ne remplacez que ce dont vous avez réellement besoin.
