# LlmClientInterface

L'interface `LlmClientInterface` est le connecteur universel de Synapse Core. C'est elle qui permet de dialoguer avec les différents fournisseurs d'IA (OpenAI, Gemini, Mistral, Ollama, etc.) en utilisant un langage commun.

## 🛠 Pourquoi l'utiliser ?

*   **Indépendance du fournisseur** : Changez de moteur d'IA en changeant une seule ligne de configuration sans toucher à votre code métier.
*   **Support du Streaming** : Permet de recevoir des réponses en temps réel "mot par mot".
*   **Standardisation** : Transforme les réponses disparates des API en objets `SynapseMessage` cohérents.

---

## 📋 Résumé du Contrat

| Méthode | Entrée | Sortie | Rôle |
| :--- | :--- | :--- | :--- |
| `supports(string $provider)` | Nom du provider | `bool` | Détermine si ce client peut gérer la demande. |
| `generateResponse(...)` | Messages + Options | `SynapseMessage` | Appel synchrone classique. |
| `generateStream(...)` | Messages + Options | `iterable` | Appel asynchrone pour streaming. |
| `getCredentialFields()` | - | `array` | Liste les clés API nécessaires (ex: `api_key`). |

---

## 🚀 Exemple : Un client factice pour vos tests

=== "FakeLlmClient.php"

    ```php
    namespace App\Synapse\Client;

    use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
    use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
    use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;

    class FakeLlmClient implements LlmClientInterface
    {
        public function supports(string $provider): bool
        {
            return $provider === 'fake';
        }

        public function generateResponse(array $messages, array $options = []): SynapseMessage
        {
            $response = new SynapseMessage();
            $response->setRole(MessageRole::MODEL);
            $response->setContent("Ceci est une réponse simulée.");
            
            return $response;
        }

        public function generateStream(array $messages, array $options = []): iterable
        {
            yield "Ceci ";
            yield "est ";
            yield "un ";
            yield "flux.";
        }

        public function getCredentialFields(): array
        {
            return ['api_key'];
        }
    }
    ```

---

## 💡 Conseils d'implémentation

> [!IMPORTANT]
> **Format OpenAI Canonical** : L'argument `$messages` reçu par ces méthodes est au format canonique OpenAI (`role` et `content`). Cela garantit une compatibilité maximale.

*   **Options LLM** : Le tableau `$options` contient les paramètres techniques tels que `temperature`, `max_output_tokens` et les outils (`tools`). Veillez à les traduire fidèlement pour votre API cible.
*   **Credential Fields** : Les champs retournés par `getCredentialFields` apparaîtront automatiquement dans l'interface d'administration de Synapse Core.

---


