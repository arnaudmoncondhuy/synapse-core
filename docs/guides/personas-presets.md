# Personas & Presets

Synapse Core vous permet de contrôler finement le comportement du LLM via deux concepts : les Personas et les Presets.

## 1. Les Personas

Un persona définit l'identité, le ton et les instructions système de l'IA.

### Configuration via JSON

Par défaut, le bundle utilise ses propres personas. Vous pouvez fournir votre propre fichier via la configuration :

```yaml
# config/packages/synapse.yaml
synapse:
    personas_path: '%kernel.project_dir%/config/personas.json'
```

### Format du fichier `personas.json`

```json
{
    "support": {
        "name": "Conseiller Client",
        "emoji": "🎧",
        "system_prompt": "Tu es un agent support courtois. Aide l'utilisateur avec ses commandes."
    }
}
```

## 2. Les Presets

Un preset est une configuration technique (modèle cible, température, outils activés) enregistrée en base de données via l'administration.

- **Modèle** : Gemini 2.0 Flash, OpenAI GPT-4o, etc.
- **Paramètres** : Max tokens, Top-P, Température.
- **Outils** : Sélection des outils autorisés pour ce preset.

## Utilisation en PHP

```php
$chatService->ask("Bonjour", [
    'persona' => 'support',
    'preset' => $myPresetObject
]);
```

> [!TIP]
> Vous pouvez lister les personas disponibles dans vos templates Twig avec la fonction : `synapse_get_personas()`.
