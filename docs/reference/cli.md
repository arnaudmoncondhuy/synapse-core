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

## `synapse:test-preset` (Interne)

Utilisé par l'interface d'administration pour valider qu'un preset est fonctionnel avant son activation.
