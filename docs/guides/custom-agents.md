# Créer un agent en code (host-side)

Synapse unifie deux mondes d'agents derrière un seul contrat :

1. **Agents "code"** : classes PHP fournies par l'application hôte (ou par Synapse lui-même) qui implémentent `AgentInterface`. Ce guide couvre ce cas.
2. **Agents "config"** : entités `SynapseAgent` gérées depuis l'admin (système prompt, preset, ton, outils). Voir [Tones & Presets](tones-presets.md#3-les-agents).

Les deux sont résolvables par nom via `AgentResolver::resolve($name)`.

!!! info "Alignement `symfony/ai` (vocabulaire uniquement)"
    Les noms `AgentInterface::call()`, `Input` et `Output` sont alignés sur `symfony/ai`. **Aucune migration n'est prévue** — c'est un alignement de vocabulaire pour laisser une porte ouverte à coût nul. Voir [AgentInterface](../reference/contracts/agent-interface.md).

---

## 1. Écrire l'agent

Créez une classe dans `src/Agent/` de votre application qui implémente `ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface` :

```php
// src/Agent/BulletinAnalyzerAgent.php
namespace App\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;

final class BulletinAnalyzerAgent implements AgentInterface
{
    public function __construct(private readonly ChatService $chatService) {}

    public function getName(): string
    {
        return 'bulletin_analyzer';
    }

    public function getDescription(): string
    {
        return 'Analyse un bulletin scolaire et produit une synthèse structurée.';
    }

    public function call(Input $input, array $options = []): Output
    {
        $result = $this->chatService->ask(
            "Analyse ce bulletin et extrais les points forts, points à améliorer et conseils : "
                . $input->getMessage(),
            ['stateless' => true, 'debug' => true],
            $input->getAttachments(),
        );

        return Output::fromChatServiceResult($result);
    }
}
```

C'est tout. **Aucune configuration à ajouter** : tant que votre `services.yaml` auto-déclare les classes sous `src/`, Synapse découvre l'agent automatiquement via le tag DI `synapse.agent` (auto-configuré par le bundle).

---

## 2. L'invoquer depuis un contrôleur / un handler / une commande

```php
use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;

final class BulletinController extends AbstractController
{
    public function __construct(private readonly AgentResolver $agents) {}

    #[Route('/bulletin/analyze', methods: ['POST'])]
    public function analyze(Request $request): Response
    {
        // Contexte racine, rempli avec la profondeur max configurée.
        $context = $this->agents->createRootContext(
            userId: $this->getUser()?->getUserIdentifier(),
            origin: 'direct',
        );

        $agent = $this->agents->resolve('bulletin_analyzer', $context);

        $output = $agent->call(
            Input::ofMessage((string) $request->getContent()),
            ['context' => $context],
        );

        return new JsonResponse([
            'answer' => $output->getAnswer(),
            'debug_id' => $output->getDebugId(),
            'usage' => $output->getUsage(),
        ]);
    }
}
```

---

## 3. Composition agent → agent (avec garde-fou)

Un agent peut en invoquer un autre. Le garde-fou de profondeur (`synapse.agents.max_depth`, défaut : 2) empêche les boucles ou les pyramides infinies. Pour un appel enfant, créez un contexte enfant depuis le contexte courant :

```php
public function call(Input $input, array $options = []): Output
{
    /** @var \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext $parent */
    $parent = $options['context'];

    // Profondeur +1, même user, préserve le workflow englobant.
    $childCtx = $parent->createChild(
        parentRunId: $parent->getRequestId(),
        childOrigin: 'code',
    );

    $summarizer = $this->agents->resolve('document_summarizer', $childCtx);
    $summary = $summarizer->call(Input::ofMessage($input->getMessage()), ['context' => $childCtx]);

    // ... combine avec d'autres étapes
    return Output::fromChatServiceResult([
        'answer' => $summary->getAnswer(),
        'usage' => $summary->getUsage(),
    ]);
}
```

Si la profondeur dépasse la limite, `AgentResolver::resolve()` lève `AgentDepthExceededException` et dispatche `AgentDepthLimitReachedEvent`.

---

## 4. Collisions de noms

Un agent code et un agent config (BDD) qui partagent le même `name` → **l'agent code gagne**, un warning est loggé. Deux agents code avec le même `name` → exception fatale au boot (erreur de programmation à corriger).

Règle pratique : choisissez des noms `snake_case` spécifiques (`bulletin_analyzer`, pas `analyzer`).

---

## 5. Traçabilité automatique

Chaque appel d'agent produit un `SynapseDebugLog` avec, si un `AgentContext` est fourni via `$options['context']` :

- `agent_run_id` (UUID de cette exécution logique),
- `parent_run_id` (UUID de l'exécution parente — null pour un appel racine),
- `depth` (profondeur d'imbrication),
- `origin` (`direct` | `code` | `config` | `ephemeral` | `workflow`).

Dans l'admin Synapse, la page Debug propose un filtre "Masquer les appels enfants" (racines uniquement par défaut), et la vue détail affiche l'arbre des appels descendants.

---

## Voir aussi

- [AgentInterface — contrat complet](../reference/contracts/agent-interface.md)
- [Créer des outils IA (Function Calling)](ai-tools.md) — même pattern d'auto-discovery
- [PresetValidatorAgent](../../src/Agent/PresetValidator/PresetValidatorAgent.php) — exemple interne d'agent multi-étapes
