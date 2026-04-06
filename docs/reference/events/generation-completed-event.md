# SynapseGenerationCompletedEvent

L'événement `SynapseGenerationCompletedEvent` est le signal de fin. Il est émis une fois que le LLM a terminé de générer sa réponse et que tous les appels d'outils ont été résolus.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Event\SynapseGenerationCompletedEvent
```

## Constructeur

```php
public function __construct(
    string $fullResponse,
    TokenUsage $usage = new TokenUsage(),
    ?string $debugId = null,
)
```

## Méthodes

| Méthode | Rôle |
|---------|------|
| `getFullResponse(): string` | Le texte complet et définitif généré par l'IA. |
| `getUsage(): TokenUsage` | Objet `TokenUsage` avec la consommation totale de tokens. |
| `getDebugId(): ?string` | ID unique de l'échange (non nul si mode debug activé). |

---

## TokenUsage

L'objet `TokenUsage` encapsule les statistiques de consommation :

```php
$usage = $event->getUsage();

$usage->promptTokens;      // Tokens en entrée
$usage->completionTokens;  // Tokens générés
$usage->totalTokens;       // Total
$usage->toArray();         // Conversion en tableau ['prompt_tokens' => ..., ...]
```

---

## Exemple : Calcul de coût et facturation

```php
namespace App\EventSubscriber;

use ArnaudMoncondhuy\SynapseCore\Event\SynapseGenerationCompletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class BillingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [SynapseGenerationCompletedEvent::class => 'onGenerationCompleted'];
    }

    public function onGenerationCompleted(SynapseGenerationCompletedEvent $event): void
    {
        $usage = $event->getUsage();

        $this->billingService->recordUsage(
            totalTokens: $usage->totalTokens,
            debugId: $event->getDebugId(),
        );
    }
}
```

---

## Voir aussi

- [SynapseExchangeCompletedEvent](./exchange-completed-event.md) — données techniques brutes (payload API)
- [Cycle de vie des événements](./overview.md) — séquence complète
