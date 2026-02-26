# SynapseExchangeCompletedEvent (Debug)

L'événement `SynapseExchangeCompletedEvent` est l'outil ultime pour le diagnostic technique. Il est déclenché à la toute fin, après la fin de la génération, et contient les données brutes des requêtes API.

## 🛠 Pourquoi l'utiliser ?

*   **Debug profond** : Examiner les en-têtes HTTP ou les payloads JSON exacts envoyés aux providers.
*   **Monitoring de sécurité** : Analyser les scores de sécurité (`safety_ratings`) renvoyés par des modèles comme Gemini.
*   **Relecture technique** : Sauvegarder l'intégralité d'un échange pour une relecture humaine ultérieure.

---

## 📋 Méthodes principales

| Méthode | Rôle |
| :--- | :--- |
| `getRawData()` | **Le Graal.** Contient les tableaux PHP des requêtes et réponses API. |
| `getSafety()` | Liste les évaluations de sécurité du provider. |
| `getProvider()` | Nom du client utilisé (ex: `gemini`). |
| `getModel()` | Identifiant exact du modèle utilisé. |

---

## 🚀 Exemple : Export vers un système d'analyse

=== "DebugSubscriber.php"

    ```php
    public function onExchangeCompleted(SynapseExchangeCompletedEvent $event): void
    {
        if ($event->isDebugMode()) {
            $this->externalDebugger->send($event->getRawData());
        }
    }
    ```

---


