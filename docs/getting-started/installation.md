# Installation

Ce guide vous accompagne dans l'installation et la configuration initiale de **Synapse Core**.

## Prérequis

- PHP 8.2 ou supérieur
- Symfony 7.0 / 8.0
- Doctrine ORM (si vous souhaitez utiliser la persistance des conversations)

## 1. Installation du package

Utilisez Composer pour ajouter le bundle à votre projet :

```bash
composer require arnaudmoncondhuy/synapse-core
```

## 2. Création des entités (Optionnel mais recommandé)

Si vous souhaitez enregistrer les conversations en base de données, vous devez créer deux entités dans votre application qui étendent les classes de base du bundle.

### Entité Conversation

```php
// src/Entity/Conversation.php
namespace App\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Conversation extends SynapseConversation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    
    public function getId(): ?int { return $this->id; }
}
```

### Entité Message

```php
// src/Entity/Message.php
namespace App\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Message extends SynapseMessage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function getId(): ?int { return $this->id; }
}
```

## 3. Configuration minimale

Créez le fichier de configuration `config/packages/synapse.yaml` :

```yaml
synapse:
    persistence:
        enabled: true
        conversation_class: App\Entity\Conversation
        message_class: App\Entity\Message

    admin:
        enabled: true
```

## 4. Mise à jour de la base de données

Générez et exécutez une migration pour créer les tables nécessaires :

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

## 5. Accès à l'administration

Une fois installé, vous pouvez accéder à l'interface d'administration pour configurer vos providers (Gemini, OpenAI, etc.) :

**URL par défaut** : `/synapse/admin`

> [!NOTE]
> Par défaut, l'accès est réservé aux utilisateurs ayant le rôle `ROLE_ADMIN`.
