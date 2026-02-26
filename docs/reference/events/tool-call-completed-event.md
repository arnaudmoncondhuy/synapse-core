# SynapseToolCallCompletedEvent

L'événement `SynapseToolCallCompletedEvent` confirme qu'un outil a fini son exécution (qu'elle soit réussie ou non). Il permet de récupérer le résultat brut avant qu'il ne soit renvoyé au LLM.

## 🛠 Pourquoi l'utiliser ?

*   **Audit de précision** : Comparer les arguments envoyés par l'IA avec le résultat réel obtenu.
*   **Logging spécifique** : Enregistrer les résultats d'outils sensibles dans une base de données de traçabilité.
*   **Intervention** : Modifier le résultat de l'outil avant qu'il n'atteigne l'IA (enveloppe de sécurité).

---

## 📋 Méthodes principales

| Méthode | Rôle |
| :--- | :--- |
| `getToolName()` | Identifiant technique de l'outil qui vient de s'exécuter. |
| `getResult()` | La valeur brute retournée par votre code PHP. |
| `getToolCallData()`| Payload complet de l'appel (arguments du LLM). |

---

## 🚀 Exemple : Logger les résultats d'API

=== "ToolLogSubscriber.php"

    ```php
    public function onToolCompleted(SynapseToolCallCompletedEvent $event): void
    {
        $this->logger->info(sprintf(
            "Outil '%s' exécuté avec succès. Résultat: %s",
            $event->getToolName(),
            json_encode($event->getResult())
        ));
    }
    ```

---


