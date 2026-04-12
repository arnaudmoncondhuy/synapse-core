# Configuration détaillée

Toute la configuration du bundle se fait dans le fichier `config/packages/synapse.yaml`.

## Référence des options

### Persistance (`persistence`)

Permet d'enregistrer les conversations et messages en base de données.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `conversation_class` | string | `null` | FQCN de votre entité Conversation (ex: `App\Entity\Conversation`). |
| `message_class` | string | `null` | FQCN de votre entité Message. |

> La persistance est automatiquement activée dès que `conversation_class` et `message_class` sont définis.

### Sécurité (`security`)

Gère les accès à l'administration et au chat.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `admin_role` | string | `ROLE_ADMIN` | Le rôle requis pour accéder à l'administration (défaut: `/synapse/admin`). |
| `chat_role` | string | `ROLE_USER` | Le rôle requis pour le chat (défaut: `/synapse/chat`). `PUBLIC_ACCESS` pour désactiver. |
| `api_csrf_enabled` | bool | `true` | Active la vérification CSRF sur les endpoints `/synapse/api/*`. |
| `mcp_trusted` | bool | `false` | Considère les requêtes sur `/_mcp` comme trusted (bypass du check admin). Nécessaire pour les clients MCP sans session HTTP. La sécurité devient alors une responsabilité réseau. |

### Routage (`routing`)

Permet de personnaliser les préfixes d'URL du bundle.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `admin_prefix` | string | `/synapse/admin` | Préfixe pour toutes les routes d'administration. |
| `chat_ui_prefix` | string | `/synapse/chat` | Préfixe pour l'interface de chat principale. |
| `chat_api_prefix` | string | `/synapse/api` | Préfixe pour les endpoints API (chat complet, CSRF, etc.). |

### Suivi de tokens & coûts (`token_tracking`)

Synapse permet de suivre l'usage et de brider la consommation.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `enabled` | bool | `false` | Active l'enregistrement des tokens et des coûts en base. |
| `reference_currency` | string | `EUR` | Devise de référence pour les plafonds et agrégats. |
| `currency_rates` | array | `{}` | Taux de conversion (ex: `{ USD: 0.92, GBP: 1.17 }`). |
| `sliding_day_hours` | int | `4` | Durée (en heures) de la fenêtre glissante pour les quotas journaliers (1–8760). |

```yaml
synapse:
    token_tracking:
        enabled: true
        reference_currency: EUR
        currency_rates:
            USD: 0.92
            GBP: 1.17
        sliding_day_hours: 4
```

### Protection CSRF

Le bundle implémente une protection CSRF automatique sur tous ses formulaires et endpoints d'API (POST/PUT/DELETE) si le composant `symfony/security-csrf` est installé et activé dans votre application.

- **Chat API** (chat, reset, memory) : jeton `synapse_api`. Le template du chat fournit déjà une meta et le composant un `data-csrf-token` ; le front envoie le header `X-CSRF-Token`.
- **Admin** : jeton `synapse_admin`. Dans le layout : `<meta name="csrf-token" content="{{ csrf_token('synapse_admin') }}">`.

En AJAX, envoyez le header `X-CSRF-Token` (ou le champ `_csrf_token` dans le body).

**Le bundle gère le CSRF sans configuration :** le front récupère le jeton via l'API si le `data-csrf-token` est absent (page surchargée, cache). Aucune action requise normalement.

**En dernier recours** (403 persistant) : désactiver la vérification CSRF sur l’API dans `config/packages/synapse.yaml` :

```yaml
synapse:
    security:
        api_csrf_enabled: false
```

Puis `php bin/console cache:clear`. L’API reste protégée par la session / le firewall.

### Chiffrement (`encryption`)

Le chiffrement est **obligatoire**. La clé de 32 bytes est utilisée pour chiffrer les credentials des providers LLM stockés en base de données.

```yaml
synapse:
    encryption:
        key: '%env(SYNAPSE_ENCRYPTION_KEY)%'
```

Générez votre clé avec :

```bash
php -r "echo base64_encode(random_bytes(32));"
```

Puis ajoutez-la dans `.env.local` (ne jamais commiter cette valeur) :

```env
SYNAPSE_ENCRYPTION_KEY=base64_de_votre_clé
```

!!! warning "Clé obligatoire"
    La configuration `encryption.key` est requise et ne peut pas être vide. Utilisez toujours `%env(SYNAPSE_ENCRYPTION_KEY)%` — jamais la clé en clair dans un fichier YAML commité.

### Rétention RGPD

La durée de rétention est configurable depuis l'interface d'administration (Paramètres → Rétention RGPD). Elle définit le nombre de jours avant que les conversations soient purgées par `synapse:purge`. Ce n'est pas une option YAML.

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

*   **Stratégie** : `Recursive` (recommandé pour la qualité sémantique) ou `Fixed`.

## Internationalisation (I18n)

Synapse utilise le composant Translation de Symfony pour gérer le multilingue.

### Domaines de traduction
- **`synapse_core`** : Promps système (date, instructions), messages d'erreur de mémoire.
- **`synapse_admin`** : Toute l'interface d'administration.
- **`synapse_chat`** : Composants Twig du chat, boutons, placeholders.

### Personnalisation
Pour modifier un texte par défaut, créez un fichier YAML dans votre projet :
`translations/synapse_chat.fr.yaml` (ou `.en.yaml`).

```yaml
synapse.chat.input_area.placeholder: "Votre message ici..."
```

### Langue par défaut
La langue est pilotée par le paramètre `synapse.context.language` (configurable par preset ou mission) et s'appuie sur la locale Symfony de la requête.

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
