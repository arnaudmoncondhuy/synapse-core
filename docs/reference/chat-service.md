# ChatService

Le `ChatService` est le point d'entrée principal de Synapse Core. C'est l'orchestrateur qui coordonne la construction du contexte (via `PromptPipeline`), la sélection du client LLM, la boucle multi-tours et la finalisation des échanges.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Engine\ChatService
```

## Méthodes publiques

### `ask(string $message, array $options, array $attachments): array`

Point d'entrée principal pour envoyer un message à l'IA.

**Paramètres :**

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `string` | Texte envoyé par l'utilisateur |
| `$options` | `array` | Options contrôlant le comportement de l'échange |
| `$attachments` | `array` | Fichiers attachés (`[['mime_type' => '...', 'data' => '...']]`) |

**Options disponibles :**

| Option | Type | Description |
|--------|------|-------------|
| `tone` | `string` | Clé du ton de réponse (ex : `'zen'`, `'efficace'`) |
| `history` | `array` | Historique manuel au format OpenAI canonical |
| `stateless` | `bool` | Si `true`, ne pas enregistrer en BDD |
| `debug` | `bool` | Activer le logging détaillé de l'échange |
| `preset` | `SynapseModelPreset` | Preset Doctrine à utiliser pour cet échange |
| `conversation_id` | `string` | ULID de la conversation à reprendre |
| `user_id` | `string` | Identifiant de l'utilisateur (pour les spending limits) |
| `estimated_cost_reference` | `float` | Coût estimé pour la vérification de plafond |
| `streaming` | `bool` | Activer ou forcer le streaming (prioritaire sur la config) |
| `reset_conversation` | `bool` | Réinitialiser la conversation avant l'envoi |
| `agent` | `string` | Clé de l'agent à utiliser |

**Retour :**

```php
[
    'answer'               => string,         // Réponse textuelle complète
    'debug_id'             => ?string,        // ID de debug (si mode debug activé)
    'usage'                => array,          // Tokens consommés
    'safety'               => array,          // Évaluations de sécurité du provider
    'model'                => string,         // Identifiant du modèle utilisé
    'preset_id'            => ?int,           // ID du preset Doctrine actif
    'agent_id'             => ?int,           // ID de l'agent Doctrine actif
    'generated_attachments'=> array,          // Images générées (modèles image-only)
]
```

### `resetConversation(): void`

Réinitialise l'historique de la conversation courante. Supprime la conversation en base de données si elle existe.

### `getConversationHistory(): array`

Retourne l'historique complet de la conversation courante au format OpenAI canonical.

---

## Flux d'exécution

Lors d'un appel à `ask()`, le `ChatService` orchestre dans l'ordre :

1. Dispatch de `SynapseGenerationStartedEvent`
2. Application du preset override (si fourni dans les options)
3. Exécution du `PromptPipeline` (5 phases : BUILD → ENRICH → OPTIMIZE → FINALIZE → CAPTURE)
4. Vérification des spending limits (`SpendingLimitChecker`)
5. Sélection du client LLM via `LlmClientRegistry`
6. Boucle multi-tours via `MultiTurnExecutor` (max `config.maxTurns`)
7. Dispatch de `SynapseGenerationCompletedEvent` et `SynapseExchangeCompletedEvent`
8. Réinitialisation de l'override (bloc `finally`)

---

## Exemple d'utilisation

```php
namespace App\Controller;

use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ChatController extends AbstractController
{
    #[Route('/ask', name: 'app_chat_ask', methods: ['POST'])]
    public function ask(ChatService $chatService): JsonResponse
    {
        $result = $chatService->ask("Bonjour, peux-tu m'aider ?");

        return $this->json([
            'answer'  => $result['answer'],
            'model'   => $result['model'],
            'tokens'  => $result['usage'],
        ]);
    }

    #[Route('/ask-with-agent', name: 'app_chat_agent', methods: ['POST'])]
    public function askWithAgent(ChatService $chatService): JsonResponse
    {
        $result = $chatService->ask(
            "Analyse ce code",
            [
                'agent'   => 'expert_symfony',
                'tone'    => 'efficace',
                'debug'   => true,
            ]
        );

        return $this->json([
            'answer'   => $result['answer'],
            'debug_id' => $result['debug_id'],
        ]);
    }
}
```

---

## Modèles image-only

Si le modèle configuré supporte uniquement la génération d'image (pas de texte), `ChatService` route automatiquement vers `ImageGenerationService`. La réponse aura `answer = ''` et `generated_attachments` contiendra les images générées.

---

## Voir aussi

- [Architecture & Flux](../explanation/architecture.md) — diagramme complet du pipeline
- [Événements](./events/overview.md) — tous les events dispatché lors d'un `ask()`
- [Conversations & Persistance](../guides/rle-management.md) — gestion de l'historique
