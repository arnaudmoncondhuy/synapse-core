# AbstractAgent

`AbstractAgent` est la classe de base recommandée pour tout agent code fourni par le bundle ou par l'application hôte. Elle implémente `AgentInterface` et centralise les comportements communs : validation de l'`AgentContext`, intégration avec le pipeline de prompt, et helpers de traçabilité.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Agent\AbstractAgent
```

## Pourquoi étendre AbstractAgent plutôt qu'implémenter AgentInterface ?

| Fonctionnalité | `AgentInterface` directement | `AbstractAgent` |
|---|---|---|
| Validation de l'AgentContext | Manuelle | Automatique (LogicException explicite si absent) |
| System prompt pipeline-aware | Non | Oui (via `getSystemPrompt()`) |
| Preset/ton/outils configurables | Non | Oui (via `getPresetKey()`, `getToneKey()`, `getAllowedToolNames()`) |
| Directive fondamentale (master prompt) | Non | Opt-in via `useMasterPrompt()` |
| Helper `buildAskOptions()` | Non | Oui (traçabilité, coûts, debug) |
| Libellé automatique | Non | Oui (snake_case → Title Case) |

## Méthodes à implémenter

### `getName(): string` (obligatoire)

Identifiant unique de l'agent en snake_case.

```php
public function getName(): string
{
    return 'bulletin_analyzer';
}
```

### `getDescription(): string` (obligatoire)

Description en langage naturel, affichée dans l'admin et utilisée par les outils MCP.

### `execute(Input $input, AgentContext $context): Output` (obligatoire)

Logique métier de l'agent. Le contexte est garanti non-null ici.

```php
protected function execute(Input $input, AgentContext $context): Output
{
    $result = $this->chatService->ask(
        $input->getMessage(),
        $this->buildAskOptions(['stateless' => true]),
        $input->getAttachments(),
    );

    return Output::fromChatServiceResult($result);
}
```

## Méthodes optionnelles (pipeline-aware)

Ces méthodes sont lues par `ContextBuilderSubscriber` lors de la phase BUILD du pipeline.

### `getSystemPrompt(): string`

Prompt système injecté automatiquement par le pipeline. Retourne `''` par défaut (mode orchestrateur : le pipeline ne touche pas au prompt).

```php
public function getSystemPrompt(): string
{
    return 'Tu es un expert en analyse de documents juridiques.';
}
```

### `getAllowedToolNames(): array`

Liste des noms d'outils autorisés pour cet agent. `[]` par défaut = tous les outils disponibles.

```php
public function getAllowedToolNames(): array
{
    return ['web_search', 'calculator'];
}
```

### `getPresetKey(): ?string`

Clé du preset LLM à utiliser. `null` par défaut = utilise le preset actif global.

### `getToneKey(): ?string`

Clé du ton de réponse à appliquer. `null` par défaut.

### `useMasterPrompt(): bool`

Indique si la Directive Fondamentale (master prompt) doit être injectée. `false` par défaut pour les agents code : l'agent est autonome. Surchargez à `true` si votre agent est conversationnel et doit hériter des règles de sécurité de l'application hôte.

```php
public function useMasterPrompt(): bool
{
    return true; // Héritage des règles de sécurité de l'app hôte
}
```

### `getEmoji(): string`

Emoji affiché dans l'admin et les debug logs. Défaut : 🤖.

### `getLabel(): string`

Libellé lisible par un humain. Par défaut : conversion du `getName()` de snake_case vers Title Case. Surchargez pour un libellé précis.

## Helper `buildAskOptions()`

Construit les options à passer à `ChatService::ask()` avec l'identification de l'agent. Garantit que chaque appel LLM est tracé, débogable et comptabilisé.

```php
protected function execute(Input $input, AgentContext $context): Output
{
    // Appel simple
    $result = $this->chatService->ask(
        $input->getMessage(),
        $this->buildAskOptions(),
    );

    // Avec options supplémentaires
    $result = $this->chatService->ask(
        $input->getMessage(),
        $this->buildAskOptions([
            'stateless' => true,
            'streaming' => false,
            'response_format' => ['type' => 'json_object'],
        ]),
    );
}
```

Les options injectées automatiquement :
- `agent` → `$this->getName()` (traçabilité, coûts, debug)
- `module` → `'agent'`
- `action` → `'agent_call'`

## Exemple complet

```php
namespace App\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\AbstractAgent;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;

final class ContractAnalyzerAgent extends AbstractAgent
{
    public function __construct(private readonly ChatService $chatService) {}

    public function getName(): string { return 'contract_analyzer'; }

    public function getDescription(): string
    {
        return 'Analyse un contrat et identifie les clauses à risque.';
    }

    public function getSystemPrompt(): string
    {
        return 'Tu es un juriste expert. Analyse le contrat fourni et identifie '
             . 'les clauses abusives, les risques et les points de négociation.';
    }

    public function getPresetKey(): ?string
    {
        return 'premium_reasoning'; // Preset LLM haute qualité pour l'analyse juridique
    }

    protected function execute(Input $input, AgentContext $context): Output
    {
        $result = $this->chatService->ask(
            $input->getMessage(),
            $this->buildAskOptions([
                'stateless' => true,
                'response_format' => [
                    'type' => 'json_object',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'clauses_a_risque' => ['type' => 'array'],
                            'points_de_negociation' => ['type' => 'array'],
                            'recommandation' => ['type' => 'string'],
                        ],
                    ],
                ],
            ]),
            $input->getAttachments(),
        );

        return Output::fromChatServiceResult($result);
    }
}
```

## Enregistrement automatique

Toute classe qui étend `AbstractAgent` (ou implémente `AgentInterface`) et se trouve dans les services auto-découverts est taggée automatiquement `synapse.agent` via `registerForAutoconfiguration()`. Le `CodeAgentRegistry` la prend en compte, et `AgentResolver::resolve($name)` sait la retourner.

## Voir aussi

- [AgentInterface — contrat complet](contracts/agent-interface.md)
- [Créer un agent en code](../guides/custom-agents.md) — guide pas à pas
- [AgentResolver](contracts/agent-interface.md#exécution-programmatique-depuis-lapplication-hôte) — résolution et invocation
