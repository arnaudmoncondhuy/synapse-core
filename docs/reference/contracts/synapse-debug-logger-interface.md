# SynapseDebugLoggerInterface

L'interface `SynapseDebugLoggerInterface` permet d'exporter et de journaliser l'intégralité des échanges techniques entre Synapse Core et les LLM.

## 🛠 Pourquoi l'utiliser ?

*   **Observabilité** : Voir exactement ce qui a été envoyé et reçu par l'IA dans vos outils de log (ELK, CloudWatch, etc.).
*   **Aide au développement** : Diagnostiquer pourquoi un outil n'a pas été appelé ou pourquoi le LLM a mal interprété un prompt.
*   **Audit** : Conserver une trace technique des interactions IA pour des besoins de conformité.

---

## 📋 Résumé du Contrat

| Méthode | Rôle |
| :--- | :--- |
| `logExchange(...)` | Enregistre l'intégralité d'un échange (Requête + Réponse + Usage). |

---

## 🚀 Exemple : Enregistrement dans les logs Symfony

=== "SymfonyDebugLogger.php"

    ```php
    namespace App\Synapse\Log;

    use ArnaudMoncondhuy\SynapseCore\Contract\SynapseDebugLoggerInterface;
    use Psr\Log\LoggerInterface;

    class SymfonyDebugLogger implements SynapseDebugLoggerInterface
    {
        public function __construct(private LoggerInterface $logger) {}

        public function logExchange(string $debugId, array $data): void
        {
            $this->logger->debug(sprintf("Synapse Exchange %s", $debugId), $data);
        }
    }
    ```

---

## 💡 Conseils d'implémentation

*   **Activation** : Le logging de debug n'est déclenché que si l'option `debug: true` est passée lors de l'appel à `ChatService::ask()`.
*   **Charge de données** : Le tableau `$data` peut être volumineux (plusieurs Mo si la conversation est longue). Veillez à ce que votre système de log puisse supporter ce volume ou filtrez les données inutiles.

---


