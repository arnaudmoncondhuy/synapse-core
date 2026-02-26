# SynapseGenerationStartedEvent

L'événement `SynapseGenerationStartedEvent` marque le point de départ de toute interaction. Il est émis par le `ChatService` avant toute analyse ou appel externe.

## 🛠 Pourquoi l'utiliser ?

*   **Initialisation** : Préparer des services tiers, démarrer un chronomètre de performance ou initialiser un ID de session.
*   **Validation précoce** : Vérifier une dernière fois si l'utilisateur a les crédits nécessaires ou si le service est disponible.
*   **Audit** : Enregistrer l'intention de l'utilisateur dans un journal de bord permanent.

---

## 📋 Méthodes principales

| Méthode | Rôle |
| :--- | :--- |
| `getMessage()` | Récupère le texte brut envoyé par l'utilisateur. |
| `getOptions()` | Liste des options techniques choisies pour cet appel. |

---

## 🚀 Exemple : Démarrer un compteur de performance

=== "MetricsSubscriber.php"

    ```php
    public function onGenerationStarted(SynapseGenerationStartedEvent $event): void
    {
        $this->metricsCollector->startTimer('llm_generation_time');
    }
    ```

---


