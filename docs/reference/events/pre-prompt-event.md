# SynapsePrePromptEvent

L'événement `SynapsePrePromptEvent` est l'un des plus importants pour la personnalisation. Il vous permet d'intervenir juste avant que la requête ne soit envoyée au LLM pour modifier le prompt système ou le contexte.

## 🛠 Pourquoi l'utiliser ?

*   **Personnalisation à la volée** : Ajouter des instructions spécifiques selon l'utilisateur ("Aujourd'hui, l'utilisateur est de mauvaise humeur, sois plus bref").
*   **Enrichissement dynamique** : Injecter des données fraîches récupérées depuis une API tierce.
*   **Contrôle final** : S'assurer que certaines règles de sécurité sont bien présentes dans le prompt système final.

---

## 📋 Méthodes principales

| Méthode | Rôle |
| :--- | :--- |
| `getSystemPrompt()` | Récupère le texte actuel du prompt système. |
| `setSystemPrompt(string)` | Écrase ou complète les instructions système. |
| `getMessages()` | Accède à l'historique complet des messages. |
| `setMessages(array)` | Permet de filtrer ou modifier l'historique avant l'envoi. |

---

## 🚀 Exemple : Injecter une humeur variable

=== "MoodSubscriber.php"

    ```php
    namespace App\EventSubscriber;

    use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapsePrePromptEvent;
    use Symfony\Component\EventDispatcher\EventSubscriberInterface;

    class MoodSubscriber implements EventSubscriberInterface
    {
        public static function getSubscribedEvents(): array
        {
            return [SynapsePrePromptEvent::class => 'onPrePrompt'];
        }

        public function onPrePrompt(SynapsePrePromptEvent $event): void
        {
            $prompt = $event->getSystemPrompt();
            $newPrompt = $prompt . "\nIMPORTANT: Réponds comme si tu étais un pirate.";
            
            $event->setSystemPrompt($newPrompt);
        }
    }
    ```

---

## 💡 Conseils d'usage

> [!WARNING]
> **Attention à la longueur** : Si vous ajoutez trop d'informations dans le prompt système via cet événement, vous risquez de dépasser la limite de tokens du modèle ou de diluer les instructions initiales.

---


