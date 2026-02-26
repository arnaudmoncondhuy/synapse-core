# SynapseChunkReceivedEvent

L'événement `SynapseChunkReceivedEvent` est la clé pour créer des interfaces réactives. Il est déclenché à chaque fois qu'un nouveau morceau de texte (token) est reçu du fournisseur d'IA en mode streaming.

## 🛠 Pourquoi l'utiliser ?

*   **Expérience "ChatGPT"** : Afficher la réponse en temps réel plutôt que d'attendre 20 secondes une réponse complète.
*   **Consommation progressive** : Traiter ou analyser le début de la réponse pendant que la fin est encore en cours de génération.
*   **Monitoring** : Suivre la vitesse de génération (tokens par seconde).

---

## 📋 Méthodes principales

| Méthode | Rôle |
| :--- | :--- |
| `getChunk()` | Retourne le fragment de texte venant d'arriver (ex: "Bonjour"). |
| `getDebugId()` | Permet de relier ce morceau à une session spécifique. |

---

## 🚀 Exemple : Diffusion vers Mercure ou WebSockets

=== "StreamingSubscriber.php"

    ```php
    namespace App\EventSubscriber;

    use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapseChunkReceivedEvent;
    use Symfony\Component\EventDispatcher\EventSubscriberInterface;

    class StreamingSubscriber implements EventSubscriberInterface
    {
        public function onChunk(SynapseChunkReceivedEvent $event): void
        {
            $text = $event->getChunk();
            // Envoyer le fragment au navigateur via WebSockets ou Server-Sent Events
            $this->webSocketSender->send('chat_topic', ['content' => $text]);
        }

        public static function getSubscribedEvents(): array
        {
            return [SynapseChunkReceivedEvent::class => 'onChunk'];
        }
    }
    ```

---

## 💡 Conseils d'usage

> [!TIP]
> **Activation** : Pour que cet événement soit déclenché, vous devez impérativement passer l'option `stream: true` lors de votre appel à `ChatService::ask()`.

---


