# AgentInterface

L'interface `AgentInterface` définit un agent IA capable d'accomplir des tâches complexes impliquant potentiellement plusieurs appels LLM, des boucles de raisonnement ou l'orchestration de sous-systèmes.

À la différence d'un `AiToolInterface` (fonction simple appelée par le LLM), un agent est invoqué directement par l'application pour accomplir un objectif de haut niveau.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface
```

## Contrat complet

```php
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;

interface AgentInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function call(Input $input, array $options = []): Output;
}
```

## Méthodes

| Méthode | Rôle |
|---------|------|
| `getName(): string` | Identifiant unique de l'agent (recommandé : snake_case, ex : `'preset_validator'`). |
| `getDescription(): string` | Description en langage naturel — utilisée dans l'admin et pour l'auto-documentation. |
| `call(Input, array): Output` | Exécute la logique de l'agent. Retourne un `Output` structuré (réponse texte, données, usage, debugId, ...). |

## Alignement `symfony/ai` (vocabulaire uniquement, pas de migration)

Les noms `call()`, `Input` et `Output` sont volontairement alignés sur `Symfony\AI\Agent\AgentInterface` / `Symfony\AI\Agent\Input` / `Symfony\AI\Agent\Output`.

!!! warning "Pas de migration prévue"
    C'est un alignement de **vocabulaire**, pas un chemin de migration. `symfony/ai` est encore en développement et aucune adoption n'est planifiée. L'intérêt est de ne pas construire une "deuxième réalité" qui serait douloureuse à rapprocher plus tard si le jour vient. Une réévaluation éventuelle n'est pas attendue avant au moins un an, et même à ce moment-là rien n'est décidé.

Écarts assumés :

- `getDescription()` est un ajout Synapse (utile pour l'admin UI), absent de `symfony/ai`.
- Le contexte d'exécution (`AgentContext` : traçabilité, profondeur, budget) est transporté via `$options['context']`, pas en paramètre typé. Cela garde la signature `call()` **call-compatible mot pour mot** avec `symfony/ai`.

!!! info "AgentInterface vs SynapseAgent"
    `AgentInterface` est le contrat PHP pour les **agents "code"** (classes fournies par le bundle ou l'application hôte, découvertes par auto-configuration DI). `SynapseAgent` est l'entité Doctrine pour les **agents "config"** (système prompt, preset, ton, outils configurés depuis l'admin). Les deux mondes sont unifiés derrière le même contrat via [`AgentResolver`](../../../src/Agent/AgentResolver.php) et la classe d'adaptation `ConfiguredAgent`.

---

## Cas d'usage typiques

- Analyse multi-documents complexe
- Validation d'un preset par simulation ([`PresetValidatorAgent`](../../../src/Agent/PresetValidator/PresetValidatorAgent.php))
- Génération de rapports structurés après plusieurs étapes de réflexion
- Orchestration de sous-agents (via `AgentResolver` + `AgentContext::createChild()`, dans la limite de `synapse.agents.max_depth`)

---

## Exemple : Agent d'analyse de document

```php
namespace App\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;

final class DocumentAnalyzerAgent implements AgentInterface
{
    public function __construct(private readonly ChatService $chatService) {}

    public function getName(): string
    {
        return 'document_analyzer';
    }

    public function getDescription(): string
    {
        return 'Analyse un document et en extrait les points clés, les risques et les actions suggérées.';
    }

    public function call(Input $input, array $options = []): Output
    {
        $result = $this->chatService->ask(
            "Analyse ce document et retourne : 1) les points clés 2) les risques 3) les actions suggérées.\n\n"
                . $input->getMessage(),
            ['stateless' => true, 'debug' => true],
            $input->getAttachments(),
        );

        return Output::fromChatServiceResult($result);
    }
}
```

## Exécution programmatique depuis l'application hôte

```php
use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;

public function __construct(private readonly AgentResolver $agents) {}

public function analyze(string $document): array
{
    $context = $this->agents->createRootContext(userId: 'user-42', origin: 'direct');
    $agent = $this->agents->resolve('document_analyzer', $context);

    $output = $agent->call(
        Input::ofMessage($document),
        ['context' => $context],
    );

    return $output->getData();
}
```

---

## Enregistrement automatique

Rien à déclarer côté hôte : toute classe qui implémente `AgentInterface` et qui se trouve dans les services auto-découverts (`src/` par défaut sous `services.yaml`) est taggée automatiquement `synapse.agent` via `registerForAutoconfiguration()` du bundle. Le `CodeAgentRegistry` la prend en compte, et `AgentResolver::resolve($name)` sait la retourner.

Voir le guide [Custom Agents](../../guides/custom-agents.md) pour un exemple complet.

---

## Voir aussi

- [Custom Agents (host-side)](../../guides/custom-agents.md) — premier agent code en 20 lignes
- [Agents via l'admin](../../guides/tones-presets.md#3-les-agents) — agents configurés sans code
- [Contrôle d'accès aux agents](../../agent-access-control.md) — restreindre l'accès par rôle
