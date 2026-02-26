# Commandes CLI

Le bundle fournit des commandes pour la maintenance et le debug.

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
