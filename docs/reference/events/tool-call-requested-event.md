# SynapseToolCallRequestedEvent

L'événement `SynapseToolCallRequestedEvent` est au cœur du mécanisme de "Function Calling". Il est déclenché lorsque le LLM décide qu'il a besoin d'utiliser un outil (ex: `get_weather`) pour répondre à l'utilisateur.

## 🛠 Pourquoi l'utiliser ?

*   **Customisation de l'exécution** : Si vous ne souhaitez pas utiliser le `ToolExecutionSubscriber` par défaut, vous pouvez capturer cet événement pour gérer vous-même l'appel de vos services.
*   **Log des intentions** : Enregistrer ce que l'IA s'apprête à faire avant qu'elle ne le fasse.
*   **Validation / Approbation** : Intercepter l'appel pour demander une validation humaine avant d'exécuter une action sensible (ex: supprimer un compte).

---

## 📋 Méthodes principales

| Méthode | Rôle |
| :--- | :--- |
| `getToolCalls()` | Liste des outils demandés avec leurs IDs et arguments. |
| `setToolResult(name, res)` | **Crucial.** Enregistre le résultat de votre code PHP pour le renvoyer au LLM. |
| `areAllResultsRegistered()`| Vérifie si tous les outils demandés ont une réponse prête. |

---

## 🚀 Exemple : Simulation de résultat manuel

=== "ManualToolSubscriber.php"

    ```php
    public function onToolRequest(SynapseToolCallRequestedEvent $event): void
    {
        foreach ($event->getToolCalls() as $call) {
            if ($call['name'] === 'calculator') {
                $result = $call['args']['a'] + $call['args']['b'];
                $event->setToolResult('calculator', $result);
            }
        }
    }
    ```

---


