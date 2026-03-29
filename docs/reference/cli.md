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

## `synapse:version:update` (Interne)

Met à jour le fichier `VERSION` avec la date courante au format `dev 0.YYMMDD`. Utilisé lors du process de release.

```bash
php bin/console synapse:version:update
```
