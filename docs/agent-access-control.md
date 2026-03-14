# Contrôle d'accès aux agents

Ce document explique comment restreindre l'accès à certains agents en fonction des rôles Symfony ou des identifiants utilisateur.

---

## 📖 Concepts

Chaque agent (`SynapseAgent`) peut avoir un contrôle d'accès configuré via le champ `accessControl` :

```php
[
    'roles' => ['ROLE_TEACHER', 'ROLE_ADMIN'],           // Rôles Symfony autorisés
    'userIdentifiers' => ['jean.dupont@example.com']     // Identifiants utilisateur autorisés
]
```

### Règles de validation

| Configuration | Comportement |
|---------------|--------------|
| `accessControl = null` | **Agent public** : accessible à tous les utilisateurs |
| `accessControl = ['roles' => [], 'userIdentifiers' => []]` | **Agent verrouillé** : accessible à personne |
| `accessControl` configuré | **Agent restreint** : accessible si l'utilisateur a au moins un rôle OU son identifiant est dans la liste |

---

## 🎯 Cas d'usage

### Exemple 1 : Agent réservé aux enseignants

```php
$agent = new SynapseAgent();
$agent->setKey('assistant_enseignant');
$agent->setName('Assistant Enseignant');
$agent->setAccessControl([
    'roles' => ['ROLE_TEACHER', 'ROLE_ADMIN'],
    'userIdentifiers' => []
]);
```

**Résultat** :
- ✅ Utilisateurs avec `ROLE_TEACHER` → Accès autorisé
- ✅ Utilisateurs avec `ROLE_ADMIN` → Accès autorisé
- ❌ Utilisateurs avec `ROLE_STUDENT` → Accès refusé

---

### Exemple 2 : Agent beta-test pour utilisateurs spécifiques

```php
$agent->setAccessControl([
    'roles' => [],
    'userIdentifiers' => ['testeur1@example.com', 'testeur2@example.com']
]);
```

**Résultat** :
- ✅ `testeur1@example.com` → Accès autorisé
- ✅ `testeur2@example.com` → Accès autorisé
- ❌ Tous les autres utilisateurs (même avec `ROLE_ADMIN`) → Accès refusé

---

### Exemple 3 : Agent mixte (rôles + utilisateurs)

```php
$agent->setAccessControl([
    'roles' => ['ROLE_RH'],
    'userIdentifiers' => ['directeur@example.com']
]);
```

**Résultat** :
- ✅ Utilisateurs avec `ROLE_RH` → Accès autorisé
- ✅ `directeur@example.com` (même sans `ROLE_RH`) → Accès autorisé
- ❌ Autres utilisateurs → Accès refusé

---

## 🖥️ Configuration via l'interface d'administration

1. Allez dans `/synapse/admin/intelligence/agents`
2. Éditez ou créez un agent
3. Dans la section "🔐 Contrôle d'accès" :
   - **Rôles autorisés** : Saisissez les rôles Symfony (un par ligne ou séparés par virgule)
   - **Utilisateurs autorisés** : Saisissez les identifiants utilisateur (un par ligne ou séparés par virgule)
4. Enregistrez

**Exemple de saisie** :

```
Rôles autorisés :
ROLE_TEACHER
ROLE_ADMIN

Utilisateurs autorisés :
jean.dupont@lycee.fr
marie.martin@lycee.fr
```

---

## 🔍 Identifiants utilisateur

L'identifiant utilisateur est fourni par la méthode `getIdentifier()` de votre entité `User` (qui implémente `ConversationOwnerInterface`).

**Exemples selon votre application** :

### Application avec emails
```php
class User implements ConversationOwnerInterface
{
    public function getIdentifier(): string
    {
        return $this->email; // jean.dupont@example.com
    }
}
```

### Application avec usernames
```php
class User implements ConversationOwnerInterface
{
    public function getIdentifier(): string
    {
        return $this->username; // jdupont
    }
}
```

### Application avec IDs publics
```php
class User implements ConversationOwnerInterface
{
    public function getIdentifier(): string
    {
        return $this->publicId; // USER-12345
    }
}
```

---

## ⚙️ Fonctionnement technique

### Filtrage automatique

Synapse filtre automatiquement les agents via `AgentRegistry` :

```php
// Récupérer tous les agents accessibles par l'utilisateur connecté
$agents = $agentRegistry->getAll();
// → Seuls les agents autorisés sont retournés

// Récupérer un agent spécifique (avec vérification de permission)
$agent = $agentRegistry->get('assistant_rh');
// → Retourne null si l'agent n'existe pas OU si l'utilisateur n'a pas les permissions
```

### Utilisation dans ChatService

```php
$chatService->ask('Question', ['agent' => 'assistant_rh']);
// → Si l'utilisateur n'a pas accès, l'agent est ignoré silencieusement
// → Le système utilise le prompt par défaut
```

**Comportement** : Pas d'erreur levée, l'UX reste fluide.

---

## 🛠️ Implémentation personnalisée

Vous pouvez implémenter votre propre logique de permission en étendant `PermissionCheckerInterface` :

```php
namespace App\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;

class CustomAgentPermissionChecker implements PermissionCheckerInterface
{
    public function canUseAgent(SynapseAgent $agent): bool
    {
        // Votre logique custom
        // Ex : vérifier un système d'ACL, interroger une API externe, etc.

        return true;
    }

    // ... autres méthodes de PermissionCheckerInterface
}
```

Puis configurez dans `services.yaml` :

```yaml
services:
    ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface:
        class: App\Security\CustomAgentPermissionChecker
```

---

## 📋 Résumé

| Aspect | Détail |
|--------|--------|
| **Configuration** | Via UI Admin (`/synapse/admin`) ou programmatiquement |
| **Stockage** | Champ JSON `access_control` dans la table `synapse_agent` |
| **Vérification** | Automatique dans `AgentRegistry::get()` et `::getAll()` |
| **Logique** | Rôles OU identifiants (union, pas intersection) |
| **Défaut** | `null` = agent public |
| **Extensible** | Oui, via implémentation custom de `PermissionCheckerInterface` |

---

## 🔗 Voir aussi

- [Documentation agents](/docs/agents.md)
- [Interface PermissionCheckerInterface](/packages/core/src/Contract/PermissionCheckerInterface.php)
- [Tests unitaires](/packages/core/tests/Unit/Security/AgentPermissionCheckerTest.php)
