# Conversations & Persistance

Synapse Core gère automatiquement l'historique des échanges pour maintenir le contexte de la discussion.

## 1. Mode Persistant (Doctrine)

Si la persistance est activée dans `synapse.yaml`, chaque appel à `ChatService::ask()` enregistre les messages dans les tables `conversation` et `message`.

### Reprendre une conversation

Il suffit de passer le `conversation_id` dans les options :

```php
$result = $chatService->ask("Quelle est la suite ?", [
    'conversation_id' => '01AN4V0... (ULID)'
]);
```

## 2. Mode Sans État (Stateless)

Si vous ne souhaitez pas enregistrer l'échange (par exemple pour un test ou un appel one-shot), utilisez l'option `stateless` :

```php
$result = $chatService->ask("Bonjour", [
    'stateless' => true
]);
```

## 3. Gestion manuelle de l'historique

Vous pouvez également injecter votre propre historique sans utiliser la base de données :

```php
$history = [
    ['role' => 'user', 'content' => 'Bonjour'],
    ['role' => 'assistant', 'content' => 'Bonjour ! Comment puis-je vous aider ?']
];

$result = $chatService->ask("Quelle heure est-il ?", [
    'history' => $history
]);
```

## 4. Nettoyage (RGPD)

Pour respecter le RGPD, vous pouvez purger les anciennes conversations via la commande CLI :

```bash
# Purge les conversations de plus de 30 jours
php bin/console synapse:purge
```
