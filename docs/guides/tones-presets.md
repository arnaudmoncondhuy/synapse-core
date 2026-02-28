# Tons de réponse & Presets

Synapse Core vous permet de contrôler finement le comportement du LLM via deux concepts distincts : les **Tons** et les **Presets**.

## 1. Les Tons de réponse

Un ton définit le **style de communication** de l'IA : registre de langue, format, posture, niveau de formalité.
Il n'affecte pas la capacité de raisonnement, uniquement la façon dont les réponses sont formulées.

### Gestion depuis l'admin

Les tons sont stockés en base de données et gérables depuis **Intelligence › Tons de réponse** dans l'admin Synapse V2.

- **20 tons builtin** sont fournis par le bundle (efficace, zen, senior_dev, etc.)
- Vous pouvez en **créer**, **modifier** et **désactiver** librement
- Les tons builtin ne peuvent pas être supprimés (marqués `isBuiltin = true`)

### Chargement initial des tons builtin

```bash
php bin/console doctrine:fixtures:load --append
```

### Utilisation en PHP

```php
$chatService->ask("Bonjour", [
    'tone' => 'zen',          // clé du ton désiré
    'preset' => $myPreset     // optionnel
]);
```

### Dans vos templates Twig

```twig
{% set tones = synapse_get_tones() %}
{% for key, tone in tones %}
    <option value="{{ key }}">{{ tone.emoji }} {{ tone.name }}</option>
{% endfor %}
```

## 2. Les Presets

Un preset est une **configuration technique** (provider LLM, modèle, température, etc.) enregistrée en base de données.

- **Provider** : Gemini, OVH AI Endpoints, etc.
- **Modèle** : gemini-2.5-flash, etc.
- **Paramètres** : Température, Top-P, Max tokens, Streaming.

Un seul preset peut être actif à la fois — il s'applique à l'ensemble du système.

## 3. Les Missions (Agents)

Une mission est une abstraction de haut niveau qui combine un **prompt système**, un **preset** (optionnel) et un **ton** (optionnel). C'est le moyen recommandé pour créer des agents spécialisés.

### Utilisation

```php
$chatService->ask("Analyse ce code", [
    'mission' => 'expert_symfony'  // Clé de la mission
]);
```

### Avantages
- **Modularité** : Changez le modèle ou le ton d'une mission sans toucher au code appelant.
- **Quotas** : Vous pouvez définir des plafonds de dépense spécifiques à une mission.
- **Réutilisabilité** : Partagez des configurations d'agents entre différents modules.


## Différence clé

| | Ton de réponse | Preset |
|---|---|---|
| Stockage | Base de données | Base de données |
| Portée | Style & ton de la réponse | Configuration technique LLM |
| Multiplicité | Plusieurs actifs simultanément | Un seul actif à la fois |
| Builtin | Oui (20 inclus) | Non |
