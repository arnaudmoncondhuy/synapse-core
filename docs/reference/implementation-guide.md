# Guide d'implémentation (Breaking Changes - Février 2026)

Ce guide accompagne la standardisation du Synapse Core sur le format OpenAI Chat Completions (LLM-agnosticism).

## 🚀 Standardisation OpenAI

Depuis février 2026, le bundle utilise le format canonique OpenAI pour tous les échanges internes. Cela permet une compatibilité totale avec n'importe quel provider (Gemini, Mistral, Claude, Ollama) sans changer la logique du service de chat.

### Ce qui a changé

1.  **Format des messages** : Toutes les instructions, y compris le prompt système, passent désormais par le tableau `contents`. Le message système est systématiquement le premier élément avec le rôle `system`.
2.  **Signature de `LlmClientInterface`** : La méthode `generateContent` ne reçoit plus `$systemInstruction` en argument séparé.
3.  **Normalisation des erreurs** : Les erreurs de sécurité (safety ratings) sont traduites en chaînes de caractères lisibles (`blocked_reason`) au lieu d'enums spécifiques aux providers.

## 🛠️ Créer un client personnalisé

Si vous souhaitez implémenter un nouveau provider, vous devez implémenter `LlmClientInterface`.

### 1. Implémenter l'interface

```php
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;

class MyCustomClient implements LlmClientInterface
{
    public function generateContent(
        array $contents, 
        array $tools = [], 
        ?string $model = null, 
        array $options = [], 
        array &$debugOut = []
    ): array {
        // 1. Extraire le système si besoin (en tête de $contents)
        $system = '';
        if ($contents[0]['role'] === 'system') {
            $system = $contents[0]['content'];
            $contents = array_slice($contents, 1);
        }

        // 2. Traduire $contents vers votre API
        // 3. Appeler votre API
        // 4. Retourner un chunk normalisé
    }
}
```

### 2. Format du Chunk normalisé

Le retour doit TOUJOURS suivre cette structure :

```php
return [
    'text'           => '...',      // Contenu texte généré
    'thinking'       => '...',      // Contenu de réflexion (si supporté)
    'function_calls' => [...],      // Appels d'outils
    'usage'          => [
        'prompt_tokens'     => 10,
        'completion_tokens' => 20,
        'total_tokens'      => 30,
    ],
    'blocked'        => false,
    'blocked_reason' => null,       // "discours haineux", "harcèlement", etc.
];
```

## 📋 Migration d'un ancien client

Si vous aviez un client pré-v0.5 :

1.  Supprimez l'argument `$systemInstruction` de vos méthodes.
2.  Récupérez l'instruction système via `$contents[0]['content']` si `$contents[0]['role'] === 'system'`.
3.  Utilisez `ModelCapabilityRegistry` pour vérifier les capacités du modèle avant l'envoi.
4.  Remplacez `blocked_category` par `blocked_reason` dans vos retours.
