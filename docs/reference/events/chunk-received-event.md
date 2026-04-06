# SynapseChunkReceivedEvent

L'événement `SynapseChunkReceivedEvent` est déclenché à chaque chunk reçu du LLM. Il est essentiel pour créer des interfaces réactives en mode streaming.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Event\SynapseChunkReceivedEvent
```

## Constructeur

```php
public function __construct(
    array $chunk,    // Chunk normalisé (text, function_calls, usage, etc.)
    int $turn = 0,   // Index du tour de parole actuel
    ?array $rawChunk = null, // Payload brut du provider (debug avancé)
)
```

## Méthodes

| Méthode | Rôle |
|---------|------|
| `getChunk(): array` | Retourne le chunk normalisé complet. |
| `getText(): ?string` | Extrait uniquement le fragment de texte généré (ou `null` si aucun texte). |
| `getThinking(): ?string` | Retourne les pensées internes du modèle si supporté (extended thinking). |
| `getFunctionCalls(): array` | Liste les appels de fonctions demandés dans ce chunk. |
| `getUsage(): array` | Statistiques d'usage (uniquement dans le dernier chunk). |
| `isBlocked(): bool` | Indique si la génération a été bloquée pour des raisons de sécurité. |
| `getTurn(): int` | Index du tour de parole (multi-step tool calls). |
| `getRawChunk(): ?array` | Payload brut du provider (pour debug avancé). |

---

## Structure du chunk normalisé

```php
[
    'text'           => '...',    // Fragment de texte (null si aucun)
    'thinking'       => '...',    // Contenu de réflexion interne (null si non supporté)
    'function_calls' => [         // Appels d'outils demandés
        ['id' => '...', 'name' => 'get_weather', 'args' => ['city' => 'Paris']],
    ],
    'usage'          => [         // Présent uniquement dans le dernier chunk
        'prompt_tokens'     => 10,
        'completion_tokens' => 5,
        'total_tokens'      => 15,
    ],
    'safety_ratings' => [...],    // Évaluations de sécurité
    'blocked'        => false,    // true si bloqué
    'blocked_reason' => null,     // ex: 'discours haineux'
]
```

---

## Exemple : Diffuser les tokens via Server-Sent Events

```php
namespace App\EventSubscriber;

use ArnaudMoncondhuy\SynapseCore\Event\SynapseChunkReceivedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StreamingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [SynapseChunkReceivedEvent::class => 'onChunk'];
    }

    public function onChunk(SynapseChunkReceivedEvent $event): void
    {
        $text = $event->getText();
        if ($text !== null) {
            // Envoyer le fragment au navigateur via SSE ou WebSocket
            $this->mercurePublisher->publish($text);
        }

        // Détecter un appel d'outil en cours
        foreach ($event->getFunctionCalls() as $call) {
            // L'outil sera exécuté par le système, mais on peut logger ici
            $this->logger->info("Outil demandé : {$call['name']}");
        }
    }
}
```

!!! tip "Activation du streaming"
    Pour que cet événement soit déclenché pour chaque token, le streaming doit être activé (option `streaming: true` ou configuré dans le preset). Sans streaming, il est déclenché une seule fois avec le contenu complet.

---

## Voir aussi

- [Cycle de vie des événements](./overview.md) — séquence complète
- [SynapseTokenStreamedEvent](./overview.md#synapsetokenstreamedevent) — granularité par token individuel
