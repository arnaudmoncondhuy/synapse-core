# Guide rapide (Quickstart)

Une fois le bundle installé, vous pouvez commencer à interagir avec les LLM en utilisant le service `ChatService`.

## 1. Injecter le service

Dans votre contrôleur ou service, injectez `ArnaudMoncondhuy\SynapseCore\Engine\ChatService`.

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
        $result = $chatService->ask("Bonjour, comment vas-tu ?");

        return $this->json([
            'answer' => $result['answer'],
            'model'  => $result['model'],
        ]);
    }
}
```

## 2. Structure de la réponse

La méthode `ask()` retourne un tableau contenant :

| Clé | Type | Description |
|-----|------|-------------|
| `answer` | `string` | La réponse textuelle du modèle. |
| `usage` | `array` | Tokens consommés (`prompt_tokens`, `completion_tokens`, `total_tokens`). |
| `model` | `string` | L'identifiant du modèle utilisé. |
| `debug_id` | `?string` | Identifiant unique si le mode debug est activé. |
| `preset_id` | `?int` | ID du preset actif. |
| `agent_id` | `?int` | ID de l'agent actif. |
| `safety` | `array` | Évaluations de sécurité du provider. |
| `generated_attachments` | `array` | Images générées (modèles image-only). |

## 3. Options courantes

Vous pouvez passer un tableau d'options en deuxième argument de `ask()` :

```php
// Avec un agent spécialisé
$result = $chatService->ask("Explique-moi la relativité", [
    'agent'    => 'expert_sciences',  // Clé de l'agent à utiliser
    'tone'     => 'efficace',         // Ton de réponse
    'stateless' => true,              // Ne pas enregistrer en BDD
]);

// Reprendre une conversation existante
$result = $chatService->ask("Et pour la mécanique quantique ?", [
    'conversation_id' => '01AN4V0... (ULID)',
    'user_id'         => (string) $user->getId(),
]);

// Injecter un historique manuel
$result = $chatService->ask("Quelle heure est-il ?", [
    'history' => [
        ['role' => 'user',      'content' => 'Bonjour'],
        ['role' => 'assistant', 'content' => 'Bonjour ! Comment puis-je vous aider ?'],
    ],
]);

// Mode debug (pour inspecter le payload dans l'admin)
$result = $chatService->ask("Test", ['debug' => true]);
$debugId = $result['debug_id']; // Utilisez cet ID dans l'admin > Logs de Debug
```

## Et après ?

- Apprenez à [Créer des outils IA](../guides/ai-tools.md) pour donner du pouvoir à votre chatbot.
- Configurez vos [Tons & Agents](../guides/tones-presets.md) pour personnaliser le comportement de l'IA.
- Découvrez comment [Gérer les Conversations](../guides/rle-management.md) persistées.
