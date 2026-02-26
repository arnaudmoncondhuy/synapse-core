# SynapseGenerationCompletedEvent

L'événement `SynapseGenerationCompletedEvent` est le signal de fin. Il est émis une fois que le LLM a fini de générer sa réponse et que tous les outils ont été résolus.

## 🛠 Pourquoi l'utiliser ?

*   **Finalisation** : Calculer le coût total de l'échange et débiter le compte de l'utilisateur.
*   **Post-traitement** : Analyser le sentiment de la réponse finale ou vérifier la présence de mots interdits.
*   **Notifications** : Envoyer une notification push si l'utilisateur n'est plus sur la page de chat.

---

## 📋 Méthodes principales

| Méthode | Rôle |
| :--- | :--- |
| `getFullResponse()` | Le texte complet et définitif généré par l'IA. |
| `getUsage()` | Statistiques de consommation (tokens d'entrée et de sortie). |
| `getDebugId()` | ID unique de l'échange (si mode debug actif). |

---

## 🚀 Exemple : Calcul de coût et facturation

=== "BillingSubscriber.php"

    ```php
    public function onGenerationCompleted(SynapseGenerationCompletedEvent $event): void
    {
        $usage = $event->getUsage();
        $totalTokens = $usage['prompt_tokens'] + $usage['completion_tokens'];
        
        $this->billingService->recordUsage($totalTokens);
    }
    ```

---


