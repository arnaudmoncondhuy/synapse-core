# Guide rapide (Quickstart)

Une fois le bundle installé, vous pouvez commencer à interagir avec les LLM en utilisant le service `ChatService`.

## 1. Injecter le service

Dans votre contrôleur ou service, injectez `ArnaudMoncondhuy\SynapseCore\Core\Chat\ChatService`.

```php
namespace App\Controller;

use ArnaudMoncondhuy\SynapseCore\Core\Chat\ChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ChatController extends AbstractController
{
    #[Route('/ask', name: 'app_chat_ask')]
    public function ask(ChatService $chatService): Response
    {
        $result = $chatService->ask("Bonjour, comment vas-tu ?");

        return $this->json([
            'answer' => $result['answer']
        ]);
    }
}
```

## 2. Structure de la réponse

La méthode `ask()` retourne un tableau contenant :

- `answer` : La réponse textuelle du modèle.
- `usage` : Détails sur les tokens consommés (prompt, completion).
- `model` : Le nom du modèle utilisé.
- `debug_id` : Un identifiant unique si le mode debug est activé.

## 3. Options courantes

Vous pouvez passer un tableau d'options en deuxième argument de `ask()` :

```php
$result = $chatService->ask("Explique-moi la relativité", [
    'persona' => 'scientifique',  // Utiliser un persona spécifique
    'stateless' => true,          // Ne pas enregistrer en BDD
    'history' => $myHistory,      // Passer un historique manuel
]);
```

## Et après ?

- Apprenez à [Créer des outils IA](../guides/ai-tools.md) pour donner du pouvoir à votre chatbot.
- Configurez vos [Personas](../guides/personas-presets.md) pour ajuster le ton de l'IA.
- Découvrez comment [Gérer les Conversations](../guides/rle-management.md) persistées.
