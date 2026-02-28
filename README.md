# Synapse Core

> Headless AI orchestration engine for Symfony — LLM clients, agents, memory, and storage.

**Synapse Core** est le cœur du framework Synapse : orchestration d'IA, support multi-LLM (Gemini, OVH AI, OpenAI), gestion de mémoire sémantique, et entités de stockage.

## Installation

```bash
composer require arnaudmoncondhuy/synapse-core:^0.1
```

## Caractéristiques principales

### 🤖 Support Multi-LLM
- **Gemini API** - Modèles à la pointe (Gemini 2.0 Flash, Pro)
- **OVH AI** - Infrastructure européenne compatible OpenAI
- **OpenAI compatible** - Framework standardisé pour tous les clients

### 🧠 Agents et Orchestration
- **SynapseAgent** - Exécution d'agents conversationnels avec tool use
- **SynapseAgentBuilder** - Construction déclarative d'agents
- **Tool Registry** - Enregistrement et exécution d'outils personnalisés

### 💾 Stockage et Entités Doctrine
- **Conversation** - Historique conversationnel
- **Message** - Messages avec roles (user, assistant, tool)
- **SynapsePreset** - Configurations d'IA réutilisables
- **SynapseProvider** - Crédentials et configuration de providers
- **SynapseModel** - Métadonnées et capabilities des modèles

### 🔐 Sécurité
- **PermissionCheckerInterface** - Vérification des droits d'accès
- **DefaultPermissionChecker** - Implémentation standard avec Symfony Security
- **LibsodiumEncryptionService** - Chiffrement XSalsa20-Poly1305 des crédentials

### 📚 Mémoire Sémantique
- **VectorStore** - Abstraction pour stockage vectoriel (DoctrineVectorStore, InMemory)
- **TextSplitter** - Découpage adaptatif de texte (RecursiveTextSplitter, FixedSize)
- **EmbeddingService** - Génération d'embeddings via Gemini ou OpenAI
- **MemoryManager** - Gestion des souvenirs avec contexte sémantique

### 💰 Accounting & Plafonds
- **TokenAccountingService** - Suivi de l'usage (input/output/thinking par conversation et par action système)
- **SpendingLimitChecker** - Vérification des quotas avant chaque requête LLM
- **SynapseTokenUsage** - Historique détaillé des dépenses avec conversion monétaire
- **SynapseSpendingLimit** - Configuration des plafonds (par utilisateur, mission ou preset)

### 🗺️ Missions et Tons
- **SynapseMission** - Abstraction d'agent combinant instructions, preset et style
- **SynapseTone** - Tons de réponse réutilisables (ex: Professionnel, Concis)
- **MissionRegistry** - Inscription et découverte dynamique des missions

### 🩺 Diagnostic
- **SynapseDoctorCommand** - Commande unique pour installer, diagnostiquer et réparer l'intégration (`synapse:doctor`)

## Configuration minimale

**config/packages/doctrine.yaml** :
```yaml
doctrine:
  orm:
    mappings:
      ArnaudMoncondhuy:
        type: attribute
        prefix: 'ArnaudMoncondhuy\SynapseCore\Storage\Entity'
        dir: '%kernel.project_dir%/vendor/arnaudmoncondhuy/synapse-core/src/Storage/Entity'
```

**config/services.yaml** :
```yaml
services:
  # Vos outils personnalisés ici
  App\Tool\YourCustomTool:
    tags:
      - synapse.tool
```

**Fusion des presets depuis DB** :
```yaml
synapse_core:
  # Le bundle charge automatiquement les providers/presets/models de la DB
```

## Événements disponibles

Le bundle dispatch plusieurs événements pour hook custom logic :

- `SynapsePrePromptEvent` - Avant l'envoi au LLM
- `SynapseGenerationStartedEvent` - Génération commencée
- `SynapseChunkReceivedEvent` - Chunk reçu (streaming)
- `SynapseGenerationCompletedEvent` - Génération terminée
- `SynapseToolCallRequestedEvent` - Tool use détecté
- `SynapseToolCallCompletedEvent` - Exécution d'outil terminée
- `SynapseExchangeCompletedEvent` - Échange complet terminé
- `SynapseUsageRecordedEvent` - Usage de tokens enregistré (Accounting)
- `SynapseSpendingLimitExceededEvent` - Plafond de dépense atteint

## Routes API disponibles

- `POST /api/chat` - Envoi de message et streaming de réponse
- `POST /api/memory` - Ajout de souvenir à la mémoire
- `POST /api/reset` - Réinitialisation de la conversation
- `POST /api/csrf` - Token CSRF pour requêtes frontend

(Routes précises définies par les bundles admin/chat)

## Structure des dépendances

```
synapse-core
  ├── symfony/framework-bundle
  ├── symfony/security-bundle
  ├── doctrine/orm
  └── symfony/messenger
```

Les bundles **admin** et **chat** dépendent de **core**.

## Licence

PolyForm Noncommercial 1.0.0 (usage non-commercial uniquement)

## 🩺 Synapse Doctor

L'assistant de diagnostic est votre meilleur allié pour l'installation et la maintenance :

```bash
# Diagnostic complet
php bin/console synapse:doctor

# Réparation automatique (création config, entités, security, routes)
php bin/console synapse:doctor --fix

# Installation fraîche
php bin/console synapse:doctor --init
```

## 💰 Gestion des Coûts (Accounting)

Synapse Core propose un système robuste pour suivre et limiter les dépenses IA.

### Configuration des Tarifs
Les tarifs sont configurés directement dans l'entité `SynapseModel` (via l'admin ou SQL) en prix par million de tokens.

### Plafonds de dépense
Vous pouvez définir des limites dans `SynapseSpendingLimit` :
- **Scopes** : `USER`, `MISSION`, `PRESET`.
- **Périodes** : `CALENDAR_DAY`, `CALENDAR_MONTH`, `SLIDING_DAY` (24h), `SLIDING_MONTH` (30j).

## 📖 Support

- 📖 [Documentation Core](https://arnaudmoncondhuy.github.io/synapse-bundle/core/)
- 🐛 [Issues](https://github.com/arnaudmoncondhuy/synapse-bundle/issues)
- 💬 [Discussions](https://github.com/arnaudmoncondhuy/synapse-bundle/discussions)

## Auteur

[Arnaud Moncondhuy](https://github.com/arnaudmoncondhuy)
