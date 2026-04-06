# LlmClientInterface

L'interface `LlmClientInterface` est le connecteur universel de Synapse Core. Elle permet de dialoguer avec n'importe quel fournisseur d'IA (OpenAI, Gemini, Mistral, Ollama, etc.) en utilisant un format unifié basé sur le standard OpenAI Chat Completions.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface
```

## Contrat complet

```php
interface LlmClientInterface
{
    public function getProviderName(): string;

    public function streamGenerateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array &$debugOut = [],
    ): \Generator;

    public function generateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array $options = [],
        array &$debugOut = [],
    ): array;

    public function getCredentialFields(): array;
    public function validateCredentials(array $credentials): void;
    public function getDefaultLabel(): string;
    public function getIcon(): string;
    public function getDefaultCurrency(): string;
    public function getProviderOptionsSchema(): array;
    public function validateProviderOptions(array $options, ModelCapabilities $caps): array;
}
```

## Méthodes

### `getProviderName(): string`

Identifiant interne du fournisseur, en minuscule sans espace (ex : `'gemini'`, `'openai'`, `'my_provider'`). Utilisé dans la configuration YAML et en base de données.

### `streamGenerateContent(array $contents, array $tools, ?string $model, array &$debugOut): \Generator`

Génère du contenu en mode streaming (Server-Sent Events). Chaque yield produit un chunk normalisé.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$contents` | `array` | Historique complet au format OpenAI canonical |
| `$tools` | `array` | Déclarations des outils disponibles (JSON Schema) |
| `$model` | `?string` | Identifiant du modèle à utiliser |
| `$debugOut` | `array&` | Sortie de debug (passage par référence) |

### `generateContent(array $contents, array $tools, ?string $model, array $options, array &$debugOut): array`

Génère du contenu en mode synchrone (bloquant). Retourne le dernier chunk normalisé.

### `getCredentialFields(): array`

Retourne la définition des champs de configuration pour l'administration. Permet de générer dynamiquement le formulaire de saisie des credentials.

```php
// Exemple de retour
return [
    'api_key' => [
        'label' => 'Clé API',
        'type' => 'password',
        'required' => true,
    ],
    'project_id' => [
        'label' => 'ID du projet',
        'type' => 'text',
        'required' => false,
        'help' => 'Disponible dans la console Google Cloud',
    ],
];
```

### `validateCredentials(array $credentials): void`

Valide l'intégrité des credentials fournis. Lève une exception si les formats sont incorrects.

### `getDefaultLabel(): string`

Nom d'affichage lisible du fournisseur dans l'interface admin (ex : `'Google Gemini'`).

### `getIcon(): string`

Icône Lucide du provider pour l'interface admin (ex : `'zap'`, `'cloud'`, `'server'`).

### `getDefaultCurrency(): string`

Devise par défaut des tarifs de ce provider (code ISO 4217, ex : `'USD'`, `'EUR'`).

### `getProviderOptionsSchema(): array`

Schéma des options spécifiques au provider pour le formulaire de preset admin.

### `validateProviderOptions(array $options, ModelCapabilities $caps): array`

Valide et nettoie les options spécifiques au provider d'un preset. Retourne les options nettoyées.

---

## Format OpenAI Canonical

L'argument `$contents` suit le format standard OpenAI Chat Completions :

```php
$contents = [
    ['role' => 'system',    'content' => 'Instructions du système...'],
    ['role' => 'user',      'content' => 'Question utilisateur'],
    ['role' => 'assistant', 'content' => 'Réponse...', 'tool_calls' => [...]],
    ['role' => 'tool',      'tool_call_id' => '...', 'content' => 'Résultat outil'],
];
```

Chaque `LlmClient` est responsable de traduire ce format vers le format natif de son API (ex : `GeminiClient` adapte OpenAI → Gemini, `OvhAiClient` passe directement).

---

## Format du chunk normalisé

La méthode `generateContent()` et les yield de `streamGenerateContent()` retournent des chunks au format normalisé :

```php
[
    'text'           => '...',         // Contenu texte généré (ou null)
    'thinking'       => '...',         // Contenu de réflexion si supporté (ou null)
    'function_calls' => [...],         // Appels d'outils demandés
    'usage'          => [
        'prompt_tokens'     => 10,
        'completion_tokens' => 20,
        'total_tokens'      => 30,
    ],
    'safety_ratings' => [...],         // Évaluations de sécurité du provider
    'blocked'        => false,         // true si la génération a été bloquée
    'blocked_reason' => null,          // ex: 'discours haineux', 'harcèlement'
]
```

!!! note "NormalizedChunk Value Object"
    Le chunk peut aussi être représenté par le Value Object `NormalizedChunk` dans les traitements internes de `ChunkProcessor`.

---

## Exemple : Implémenter un client personnalisé

```php
namespace App\Llm;

use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;

class MyCustomClient implements LlmClientInterface
{
    public function getProviderName(): string
    {
        return 'my_provider';
    }

    public function getDefaultLabel(): string
    {
        return 'Mon Provider IA';
    }

    public function getIcon(): string
    {
        return 'zap';
    }

    public function getDefaultCurrency(): string
    {
        return 'EUR';
    }

    public function streamGenerateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array &$debugOut = [],
    ): \Generator {
        // 1. Extraire le message système si présent
        $system = '';
        if (!empty($contents[0]) && $contents[0]['role'] === 'system') {
            $system = $contents[0]['content'];
            $contents = array_slice($contents, 1);
        }

        // 2. Traduire vers votre format API
        // 3. Appeler votre API en streaming
        // 4. Yield des chunks normalisés
        yield [
            'text'    => 'Réponse simulée',
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'blocked' => false,
        ];
    }

    public function generateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array $options = [],
        array &$debugOut = [],
    ): array {
        // Implémentation synchrone
        return [
            'text'    => 'Réponse simulée',
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'blocked' => false,
        ];
    }

    public function getCredentialFields(): array
    {
        return [
            'api_key' => ['label' => 'Clé API', 'type' => 'password', 'required' => true],
        ];
    }

    public function validateCredentials(array $credentials): void
    {
        if (empty($credentials['api_key'])) {
            throw new \InvalidArgumentException('La clé API est requise.');
        }
    }

    public function getProviderOptionsSchema(): array
    {
        return ['fields' => []];
    }

    public function validateProviderOptions(array $options, ModelCapabilities $caps): array
    {
        return $options;
    }
}
```

---

## Voir aussi

- [Guide d'implémentation](../implementation-guide.md) — guide complet pour créer un provider
- [ModelCapabilityRegistry](../../explanation/architecture.md) — vérification des capacités avant envoi
