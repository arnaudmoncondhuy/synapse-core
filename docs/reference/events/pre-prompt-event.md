# Événements du Pipeline de Prompt

!!! warning "SynapsePrePromptEvent est déprécié"
    Depuis mars 2026, `SynapsePrePromptEvent` est **déprécié**. Il est remplacé par 5 events de phase explicites dans `ArnaudMoncondhuy\SynapseCore\Event\Prompt\`. Migrez dès que possible.

---

## Nouveau système : 5 phases explicites

Le pipeline de prompt s'exécute en 5 phases séquentielles, chacune disposant de son propre event :

| Phase | Event | Subscribers actifs | Rôle |
|-------|-------|-------------------|------|
| 1. BUILD | `PromptBuildEvent` | `ContextBuilderSubscriber` | Construction du prompt de base (system + history + tools) |
| 2. ENRICH | `PromptEnrichEvent` | `MemoryContextSubscriber`, `RagContextSubscriber` | Enrichissement (mémoire vectorielle, RAG) |
| 3. OPTIMIZE | `PromptOptimizeEvent` | `ContextTruncationSubscriber` | Troncature du contexte selon la context window |
| 4. FINALIZE | `PromptFinalizeEvent` | `MasterPromptSubscriber` | Injection du master prompt global |
| 5. CAPTURE | `PromptCaptureEvent` | `DebugLogSubscriber` | Capture debug (lecture seule, ne modifie pas le prompt) |

Tous ces events étendent `AbstractPromptEvent` et partagent la même interface :

```php
// Méthodes disponibles sur tous les events de phase
$event->getMessage(): string          // Message brut de l'utilisateur
$event->getOptions(): array           // Options passées à ChatService::ask()
$event->getPrompt(): array            // Prompt courant (mutable)
$event->setPrompt(array $prompt)      // Modifier le prompt
$event->getConfig(): ?SynapseRuntimeConfig  // Config runtime courante (mutable)
$event->setConfig(SynapseRuntimeConfig)     // Modifier la config
$event->getAttachments(): array       // Fichiers attachés
$event->setAttachments(array)         // Modifier les pièces jointes
```

---

## Migration depuis SynapsePrePromptEvent

```php
// AVANT (déprécié)
use ArnaudMoncondhuy\SynapseCore\Event\SynapsePrePromptEvent;

#[AsEventListener(event: SynapsePrePromptEvent::class, priority: 40)]
public function onPrePrompt(SynapsePrePromptEvent $event): void
{
    $prompt = $event->getPrompt();
    // ... modifier le prompt
}

// APRÈS — choisir la phase correspondant à votre usage :

// Pour enrichir avec du contexte (priorité 40 ≈ ENRICH)
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptEnrichEvent;

#[AsEventListener(event: PromptEnrichEvent::class)]
public function onEnrich(PromptEnrichEvent $event): void
{
    $prompt = $event->getPrompt();
    // Ajouter au prompt système (premiers éléments du tableau contents)
    $contents = $prompt['contents'] ?? [];
    // ... enrichir $contents
    $event->setPrompt(['contents' => $contents]);
}
```

---

## Exemple : Injecter une instruction dynamique en phase ENRICH

```php
namespace App\EventSubscriber;

use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptEnrichEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class UserContextEnricher implements EventSubscriberInterface
{
    public function __construct(private Security $security) {}

    public static function getSubscribedEvents(): array
    {
        return [PromptEnrichEvent::class => 'onEnrich'];
    }

    public function onEnrich(PromptEnrichEvent $event): void
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return;
        }

        $prompt = $event->getPrompt();
        $contents = $prompt['contents'] ?? [];

        // Trouver le message système existant et l'enrichir
        foreach ($contents as &$message) {
            if ($message['role'] === 'system') {
                $message['content'] .= sprintf(
                    "\n\nContexte utilisateur : %s",
                    $user->getUserIdentifier()
                );
                break;
            }
        }
        unset($message);

        $event->setPrompt(array_merge($prompt, ['contents' => $contents]));
    }
}
```

---

## SynapsePrePromptEvent (déprécié)

Namespace : `ArnaudMoncondhuy\SynapseCore\Event\SynapsePrePromptEvent`

Cet event est conservé pour compatibilité ascendante mais ne sera plus dispatché dans une prochaine version majeure. Son API est identique à `AbstractPromptEvent`.

```php
// API disponible (même interface que les nouveaux events)
$event->getMessage(): string
$event->getOptions(): array
$event->getPrompt(): array
$event->setPrompt(array $prompt)
$event->getConfig(): ?SynapseRuntimeConfig
$event->setConfig(SynapseRuntimeConfig $config)
$event->getAttachments(): array
$event->setAttachments(array $attachments)
```

!!! danger "Suppression future"
    `SynapsePrePromptEvent` sera supprimé dans une prochaine version majeure. Migrez vers les events de phase dès maintenant.
