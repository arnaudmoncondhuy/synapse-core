# Commandes CLI

Le bundle fournit des commandes pour la maintenance et le debug.

## `synapse:doctor`

La commande la plus importante pour la maintenance. Elle diagnostique et répare automatiquement les problèmes d'intégration.

```bash
# Lancer le diagnostic
php bin/console synapse:doctor

# Réparer les problèmes détectés
php bin/console synapse:doctor --fix

# Initialisation complète (nouveau projet)
php bin/console synapse:doctor --init
```

**Actions effectuées par le doctor :**
- Vérification version PHP et extensions (Sodium).
- Inscription des bundles dans `bundles.php`.
- Diagnostic des entités personnalisées et création si nécessaire.
- Vérification du mapping `AssetMapper` (Stimulus).
- Vérification de la sécurité (firewalls, access_control) et génération de config.
- Vérification des routes et de la base de données (PostgreSQL, pgvector).

## `synapse:purge`


Purge les conversations trop anciennes selon la politique de rétention définie dans `synapse.yaml`.

```bash
# Simulation
php bin/console synapse:purge --dry-run
# Purge réelle
php bin/console synapse:purge
```

## `synapse:debug:embedding`

Teste la génération d'un embedding avec le provider actif. Utile pour diagnostiquer les problèmes de connexion au service d'embeddings.

```bash
# Tester avec un texte
php bin/console synapse:debug:embedding "Bonjour monde"

# Tester avec un texte et afficher le vecteur complet
php bin/console synapse:debug:embedding "Bonjour monde" --show-vector
```

## `synapse:spending:warm-cache`

Recalcule les compteurs de dépenses depuis la base de données et met à jour le cache. Utile après une migration, une correction manuelle en base, ou si le cache a été vidé.

```bash
php bin/console synapse:spending:warm-cache
```

## `synapse:rag:reindex`

Réindexe une ou toutes les sources RAG : chunking des documents + génération des embeddings.

```bash
# Réindexer une source spécifique
php bin/console synapse:rag:reindex lycee_intranet

# Réindexer toutes les sources
php bin/console synapse:rag:reindex --all

# Mode simulé (dry-run)
php bin/console synapse:rag:reindex --dry-run
```

**Fonctionnement** :
- Récupère les documents depuis le provider de source RAG (`RagSourceProviderInterface`)
- Découpe chaque document selon la stratégie de chunking (Recursive ou Fixed)
- Génère les embeddings pour chaque chunk via `EmbeddingService`
- Persiste les chunks vectorisés dans `SynapseRagDocument`

## `synapse:rag:test`

Teste une requête de recherche RAG pour diagnostiquer la pertinence et la qualité des résultats.

```bash
# Test simple
php bin/console synapse:rag:test "ma requête de test"

# Avec source spécifique et limite de résultats
php bin/console synapse:rag:test "comment configurer l'authentification ?" --source=lycee_intranet --limit=5

# Afficher les scores de pertinence (cosine similarity)
php bin/console synapse:rag:test "my query" --show-scores
```

**Affiche** :
- Résultats vectoriels (chunks similaires)
- Score de pertinence (0.0–1.0) pour chacun
- Contenu du chunk pour validation
- Métadonnées d'origine (file, page, etc.)

## `synapse:agent:test-suite`

Exécute la batterie de tests reproductibles d'un agent (Garde-fou #4). Utile en CI/CD pour détecter des régressions après une modification de prompt.

```bash
# Exécuter tous les cas de test d'un agent
php bin/console synapse:agent:test-suite support_client

# Avec un seuil de tolérance aux échecs
php bin/console synapse:agent:test-suite support_client --fail-threshold=2

# Afficher les réponses brutes de l'agent
php bin/console synapse:agent:test-suite support_client --verbose-answers
```

**Codes de sortie :**
- `0` : tous les cas sont passés
- `1` : au moins un cas a échoué (ou le seuil `--fail-threshold` est dépassé)
- `2` : erreur d'exécution (agent introuvable, exception LLM, etc.)

Les cas de test (`SynapseAgentTestCase`) sont créés depuis l'admin ou via data fixtures.

## `synapse:architect`

Génère une définition d'agent ou de workflow via l'agent architecte (LLM-driven). Permet de créer des agents et workflows depuis une description en langage naturel.

```bash
# Créer un agent
php bin/console synapse:architect create-agent "Un agent de support technique pour les utilisateurs"

# Améliorer le prompt d'un agent existant
php bin/console synapse:architect improve-prompt "Rendre le prompt plus concis" --agent-key=support_technique

# Créer un workflow multi-agents
php bin/console synapse:architect create-workflow "Analyser un document puis le résumer"

# Mode simulation (affiche sans appliquer)
php bin/console synapse:architect create-agent "..." --dry-run
```

**Actions disponibles :**
- `create-agent` : Crée un nouvel agent (inactif, prompt en pending)
- `improve-prompt` : Propose une nouvelle version de prompt pour un agent existant
- `create-workflow` : Crée un nouveau workflow (inactif)

**Options :**
- `--dry-run` : Affiche la proposition sans la persister
- `--agent-key=<key>` : Clé de l'agent cible (requis pour `improve-prompt`)
- `--instructions=<texte>` : Directives supplémentaires pour le LLM

## `synapse:preset:suggest`

Recommande et crée un preset LLM optimal, soit par heuristique automatique, soit via appel LLM.

```bash
# Suggestion automatique (heuristique)
php bin/console synapse:preset:suggest

# Suggestion LLM-assistée avec description
php bin/console synapse:preset:suggest "Je veux un modèle rapide et économique"

# Avec activation automatique du preset créé
php bin/console synapse:preset:suggest --heuristic --activate

# Filtrer par provider, sans créer
php bin/console synapse:preset:suggest --provider=anthropic --dry-run
```

**Options :**
- `--dry-run` : Affiche la recommandation sans créer le preset
- `--activate` : Active le preset créé comme preset par défaut
- `--provider=<slug>` : Filtre les modèles par provider (ex: `anthropic`, `ovh`)
- `--heuristic` : Force le mode heuristique sans appel LLM

## `synapse:version:update` (Interne)

Met à jour le fichier `VERSION` avec la date courante au format `dev 0.YYMMDD`. Utilisé lors du process de release.

```bash
php bin/console synapse:version:update
```
