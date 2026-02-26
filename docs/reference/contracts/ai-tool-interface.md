# AiToolInterface

L'interface `AiToolInterface` est le point d'entrée pour étendre les capacités de votre IA (Function Calling). Elle permet au modèle de sortir de sa "bulle" de texte pour interagir avec votre système : base de données, API tierces, ou calculs complexes.

## 🛠 Pourquoi l'utiliser ?

*   **Accès aux données** : Permettre à l'IA de consulter les stocks, les prix ou les profils utilisateurs.
*   **Actions concrètes** : Envoyer un email, créer un ticket support ou déclencher un export.
*   **Fiabilité** : Confier les calculs mathématiques ou les requêtes SQL précises à votre code PHP plutôt qu'à l'imagination du LLM.

---

## 📋 Résumé du Contrat

| Méthode | Rôle | Importance pour l'IA |
| :--- | :--- | :--- |
| `getName()` | Identifiant technique unique. | Crucial pour l'appel. |
| `getDescription()` | Explication en langage naturel de ce que fait l'outil. | Détermine **quand** l'IA choisit d'utiliser cet outil. |
| `getInputSchema()` | Structure attendue des arguments (JSON Schema). | Guide l'IA pour qu'elle fournisse les bons paramètres. |
| `execute(array $params)` | Votre logique métier PHP. | Le résultat sera renvoyé au modèle. |

---

## 🚀 Exemple : Outil de consultation météo

Voici comment implémenter un outil simple mais robuste.

=== "WeatherTool.php"

    ```php
    namespace App\Synapse\Tool;

    use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;

    class WeatherTool implements AiToolInterface
    {
        public function getName(): string
        {
            return 'get_current_weather';
        }

        public function getDescription(): string
        {
            return 'Récupère la météo actuelle pour une ville donnée afin d\'informer l\'utilisateur.';
        }

        public function getInputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'La ville et l\'état, ex: Paris, FR',
                    ],
                    'unit' => [
                        'type' => 'string',
                        'enum' => ['celsius', 'fahrenheit'],
                    ],
                ],
                'required' => ['location'],
            ];
        }

        public function execute(array $parameters): mixed
        {
            $location = $parameters['location'];
            // Votre logique d'appel API (ex: OpenWeatherMap)
            return "Il fait 22°C et grand soleil à " . $location;
        }
    }
    ```

---

## 💡 Conseils d'implémentation

> [!TIP]
> **Soignez la description !** Le LLM ne lit pas votre code PHP. Sa seule façon de savoir s'il doit appeler votre outil est de lire le texte renvoyé par `getDescription()`. Soyez explicite sur les bénéfices de l'outil.

*   **Format de retour** : La méthode `execute` peut retourner n'importe quel type `mixed` (array, string, int). Synapse s'occupe de le sérialiser proprement en JSON pour le renvoyer au modèle.
*   **Gestion des erreurs** : Si votre outil échoue, retournez un message d'erreur clair sous forme de chaîne. L'IA pourra ainsi expliquer le problème à l'utilisateur ou tenter de corriger ses paramètres.
*   **Sécurité** : N'oubliez pas que les paramètres reçus dans `execute` proviennent d'une IA et peuvent être erronés ou malveillants. Validez-les comme n'importe quelle entrée utilisateur.

---


