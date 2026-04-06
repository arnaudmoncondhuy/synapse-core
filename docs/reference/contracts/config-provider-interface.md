# ConfigProviderInterface

L'interface `ConfigProviderInterface` permet d'obtenir et de surcharger dynamiquement la configuration runtime de l'IA (modèle, température, preset actif, etc.).

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface
```

## Contrat complet

```php
interface ConfigProviderInterface
{
    public function getConfig(): SynapseRuntimeConfig;
    public function setOverride(?SynapseRuntimeConfig $config): void;
    public function getConfigForPreset(SynapseModelPreset $preset): SynapseRuntimeConfig;
}
```

## Méthodes

| Méthode | Rôle |
|---------|------|
| `getConfig(): SynapseRuntimeConfig` | Retourne la configuration dynamique active sous forme typée. |
| `setOverride(?SynapseRuntimeConfig $config): void` | Configure un override temporaire en mémoire (réinitialisé après chaque échange). |
| `getConfigForPreset(SynapseModelPreset $preset): SynapseRuntimeConfig` | Retourne la configuration complète pour un preset Doctrine spécifique. |

!!! note "Objet typé, pas un tableau"
    Contrairement à une configuration YAML statique, `getConfig()` retourne un objet `SynapseRuntimeConfig` qui encapsule tous les paramètres runtime : modèle, température, streaming, maxTurns, presetId, agentId, debugMode, etc.

---

## SynapseRuntimeConfig

Le Value Object `SynapseRuntimeConfig` contient les propriétés suivantes (principales) :

```php
class SynapseRuntimeConfig
{
    public ?string $model;
    public ?string $provider;
    public ?float $temperature;
    public ?int $maxOutputTokens;
    public bool $debugMode;
    public ?int $presetId;
    public ?int $agentId;
    public ?int $maxTurns;
    // ... et d'autres paramètres techniques
}
```

---

## Override temporaire

Le `ChatService` utilise `setOverride()` pour appliquer temporairement la configuration d'un preset ou d'un agent. L'override est **toujours réinitialisé** (`null`) à la fin de l'échange (même en cas d'exception) via un bloc `finally`.

```php
// Exemple interne dans ChatService::ask()
$this->configProvider->setOverride($config);
// ... traitement ...
$this->configProvider->setOverride(null); // dans le bloc finally
```

!!! warning "Mode FrankenPHP Worker"
    En mode worker (processus long), les services sont partagés entre requêtes. La réinitialisation de l'override dans `finally` est critique pour éviter qu'une config d'une requête ne contamine la suivante.

---

## Pourquoi l'utiliser ?

- **Adaptabilité** : utiliser une température basse pour l'analyse de données, haute pour la créativité.
- **A/B Testing** : comparer différents réglages de modèles sans modifier le code source.
- **Override par requête** : passer un preset spécifique via l'option `preset` de `ChatService::ask()`.

---

## Voir aussi

- [ChatService](../chat-service.md) — utilise `ConfigProviderInterface` pour chaque échange
- [Presets via l'admin](../../guides/tones-presets.md#2-les-presets) — presets gérés en BDD
