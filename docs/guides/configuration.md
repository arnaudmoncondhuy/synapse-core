# Configuration détaillée

Toute la configuration du bundle se fait dans le fichier `config/packages/synapse.yaml`.

## Référence des options

### Persistance (`persistence`)

Permet d'enregistrer les conversations et messages en base de données.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `enabled` | bool | `false` | Activer la persistance Doctrine. |
| `conversation_class` | string | `null` | FQCN de votre entité Conversation (ex: `App\Entity\Conversation`). |
| `message_class` | string | `null` | FQCN de votre entité Message. |

### Sécurité (`security`)

Gère les accès à l'administration et au chat.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `admin_role` | string | `ROLE_ADMIN` | Le rôle requis pour accéder à `/synapse/admin` (via le checker par défaut). |
| `permission_checker` | string | `default` | Service de sécurité. Par défaut, bloque l'admin si la sécurité Symfony est absente. |

### Protection CSRF

Le bundle implémente une protection CSRF automatique sur tous ses formulaires et endpoints d'API (POST/PUT/DELETE) si le composant `symfony/security-csrf` est installé et activé dans votre application.

- **Chat API** (`/synapse/api/chat`, reset, memory) : jeton `synapse_api`. Le template du chat fournit déjà une meta et le composant un `data-csrf-token` ; le front envoie le header `X-CSRF-Token`.
- **Admin** : jeton `synapse_admin`. Dans le layout : `<meta name="csrf-token" content="{{ csrf_token('synapse_admin') }}">`.

En AJAX, envoyez le header `X-CSRF-Token` (ou le champ `_csrf_token` dans le body).

**Le bundle gère le CSRF sans configuration :** le front récupère le jeton via GET `/synapse/api/csrf-token` si la meta ou le `data-csrf-token` est absent (page surchargée, cache). Aucune action requise normalement.

**En dernier recours** (403 persistant) : désactiver la vérification CSRF sur l’API dans `config/packages/synapse.yaml` :

```yaml
synapse:
    security:
        api_csrf_enabled: false
```

Puis `php bin/console cache:clear`. L’API reste protégée par la session / le firewall.

### Chiffrement (`encryption`)

Pour sécuriser vos clés API et vos messages en base de données.

```yaml
synapse:
    encryption:
        enabled: true
        key: '%env(SYNAPSE_ENCRYPTION_KEY)%'
```

> [!WARNING]
> N'activez le chiffrement que si vous avez configuré une clé 32 bytes valide.

### Rétention RGPD (`retention`)

Suppression automatique des anciennes conversations.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `days` | int | `30` | Nombre de jours avant que les conversations ne soient purgées par `synapse:purge`. |

### Mémoire Vectorielle (`vector_store`)

Permet de choisir l'implémentation de stockage pour le RAG.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `default` | string | `null` | L'implémentation à utiliser : `null`, `in_memory`, `doctrine` ou un service ID personnalisé. |

> [!TIP]
> L'option **`doctrine`** détecte automatiquement la présence de l'extension `pgvector` sur PostgreSQL pour des performances optimales.

### Chunking et Stratégies

Ces paramètres sont gérés globalement dans l'interface d'administration Synapse (onglet Embeddings). Ils définissent comment les documents sont découpés avant d'être envoyés à l'IA.

*   **Taille des segments** : Taille maximale d'un chunk (ex: 1000 caractères).
*   **Chevauchement (Overlap)** : Nombre de caractères partagés entre deux segments pour garder le contexte.
*   **Stratégie** : `Recursive` (recommandé pour la qualité sémantique) ou `Fixed`.

## Variables d'environnement

Voici les variables recommandées à définir dans votre `.env` :

```env
# Clé de chiffrement (32 bytes)
SYNAPSE_ENCRYPTION_KEY=...
# Rôle admin custom
SYNAPSE_ADMIN_ROLE=ROLE_SUPER_ADMIN
```

## Vérification

Vous pouvez voir la configuration finale résolue par Symfony avec la commande :

```bash
php bin/console config:dump synapse
```
