# Agents IA — Synapse Bundle

## Philosophie

Un **agent** est un composant qui **orchestre** plusieurs appels LLM pour accomplir une tâche complexe.

Contrairement à un **tool** (fonction simple, rapide, stateless), un agent :
- ⏱️ Peut durer plusieurs secondes
- 🔄 Effectue plusieurs appels LLM
- 💾 Maintient un état interne
- 🎯 Orchestre d'autres systèmes

**Principe clé** : Agents et Tools sont **fondamentalement différents**. Ne jamais les mélanger.

---

## Architecture

### Contract

```php
// src/Contract/AgentInterface.php
interface AgentInterface
{
    public function getName(): string;              // Identifiant unique: 'preset_validator'
    public function getDescription(): string;      // Naturel language pour UI/LLM
    public function run(array $input): array;      // Exécution avec paramètres
}
```

### Organisation

```
Agent/
  PresetValidator/
    PresetValidatorAgent.php    // Implémentation
  ConversationAnalyzer/         // Futur agent
    ConversationAnalyzerAgent.php
  ...
README.md                        // Ce fichier
```

### Enregistrement

Dans `config/services.yaml` :

```yaml
# ── Agents ────────────────────────────────────────────────────────────────
ArnaudMoncondhuy\SynapseBundle\Agent\PresetValidator\PresetValidatorAgent:
    autowire: true
    autoconfigure: true
```

**Pas de tag DI** — Agents ne sont **pas** des tools.

---

## Créer un nouvel agent

### Étape 1 : Créer la classe

```php
// src/Agent/MyFeature/MyFeatureAgent.php
namespace ArnaudMoncondhuy\SynapseBundle\Agent\MyFeature;

use ArnaudMoncondhuy\SynapseBundle\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseBundle\Service\ChatService;

class MyFeatureAgent implements AgentInterface
{
    public function __construct(
        private ChatService $chatService,
        // ... dépendances métier
    ) {}

    public function getName(): string
    {
        return 'my_feature';  // snake_case
    }

    public function getDescription(): string
    {
        return 'Fait quelque chose d\'intéressant avec les LLMs.';
    }

    public function run(array $input): array
    {
        // $input peut contenir : ['param1' => 'value', ...]
        // Orchestrer ChatService, appels multiples, etc.

        return [
            'result'   => '...',
            'debug_id' => '...',
            // Retourner une structure claire
        ];
    }
}
```

### Étape 2 : Enregistrer dans services.yaml

```yaml
ArnaudMoncondhuy\SynapseBundle\Agent\MyFeature\MyFeatureAgent:
    autowire: true
    autoconfigure: true
```

### Étape 3 : Utiliser

```php
class MyFeatureController
{
    public function __construct(
        private MyFeatureAgent $agent,
    ) {}

    public function execute(): Response
    {
        $result = $this->agent->run(['param' => 'value']);
        // ...
    }
}
```

---

## Exemple : PresetValidatorAgent

```
Purpose: Valider les configurations de presets LLM

Workflow:
  1. Appel LLM avec preset cible → capture debug (request + response bruts)
  2. Appel LLM d'analyse → reçoit 4 JSONs, produit rapport Markdown

Return: {
    'preset': SynapsePreset,
    'ai_response': string (réponse au message de test),
    'debug_id': string (référence DB),
    'critical_checks': ['response_not_empty' => bool, 'debug_saved_in_db' => bool],
    'all_critical_ok': bool,
    'analysis': string (Markdown),
    'usage_test': array (tokens),
}
```

---

## Patterns

### Multi-LLM Calls

```php
public function run(array $input): array
{
    // Appel 1 : Extraction / Test
    $result1 = $this->chatService->ask($msg1, [
        'debug' => true,
        'tools' => [],  // ⚠️ IMPORTANT: éviter récursion
    ]);

    // Traitement intermédiaire
    $data = $this->processResult($result1);

    // Appel 2 : Analyse
    $result2 = $this->chatService->ask($msg2, [
        'stateless' => true,
        'tools' => [],
    ]);

    return ['analysis' => $result2['answer'], ...];
}
```

### Éviter la récursion

⚠️ **Critique** : Toujours passer `tools: []` dans les appels internes à ChatService.

Si l'agent est exposé accidentellement comme tool, cela prévient la récursion infinie.

---

## Futures extensions

- [ ] Exposer un agent comme **tool** via un wrapper explicite (pour composition d'agents)
- [ ] Système de cache pour résultats d'agents
- [ ] Logging/tracing d'exécution agent
- [ ] Tests automatisés pour agents
- [ ] Orchestrateur d'agents (langage de sélection automatique)

---

## FAQ

**Q: Pourquoi pas implémenter AiToolInterface?**
A: Tools et agents sont différents. Implémenter AiToolInterface exposerait accidentellement
l'agent comme tool dans TOUTES les conversations (coûteux, dangereux). Agents ont leur propre interface.

**Q: Comment composer des agents (agent qui utilise un autre agent)?**
A: Injection directe dans le constructeur. L'orchestrateur injecte les sous-agents.

**Q: Comment tester un agent?**
A: Injecter un mock ChatService. Les agents doivent être testables en isolation.

**Q: Puis-je utiliser des outils dans un agent?**
A: Oui! `$this->chatService->ask($msg, ['tools' => $tools])` fonctionne. L'agent coordonne, ChatService gère les tools.
