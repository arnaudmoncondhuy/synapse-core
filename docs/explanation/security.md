# Sécurité & RGPD

La sécurité et la protection des données sont au cœur de Synapse Core.

## Chiffrement des données

Si activé, Synapse utilise **libsodium** (XSalsa20-Poly1305) pour chiffrer :
- Les messages des utilisateurs en base de données.
- Les credentials des providers (clés API, JSON de service account).

## RGPD : Rétention des données

Le bundle inclut une commande de purge automatique (`synapse:purge`) qui doit être configurée en tâche CRON pour respecter la durée de conservation maximale de 30 jours (ou plus selon votre config).

## Contrôle d'accès

L'administration est sécurisée par le système de sécurité de Symfony :
- Rôle par défaut : `ROLE_ADMIN`.
- Possibilité d'implémenter `PermissionCheckerInterface` pour des règles plus fines.
