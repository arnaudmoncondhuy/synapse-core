# ContextProviderInterface

L'interface `ContextProviderInterface` permet d'injecter dynamiquement un prompt système et des données contextuelles au début de chaque échange avec l'IA.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface
```

## Contrat complet

```php
interface ContextProviderInterface
{
    public function getSystemPrompt(): string;
    public function getInitialContext(): array;
}
```

## Méthodes

| Méthode | Rôle |
|---------|------|
| `getSystemPrompt(): string` | Retourne le prompt système principal (identité et règles de base de l'IA). |
| `getInitialContext(): array` | Retourne des données contextuelles additionnelles (converties en texte et injectées après le prompt système). |

---

## Pourquoi l'utiliser ?

- **Prompt Engineering dynamique** : injecter le nom de l'utilisateur, ses préférences ou son contexte métier.
- **Multilinguisme** : adapter la langue des instructions selon la session.
- **Isolation des données** : donner à l'IA uniquement ce dont elle a besoin pour le cas d'usage.

!!! tip "Fraîcheur des données"
    Ces méthodes sont appelées au moment de la génération. Les données injectées sont toujours à jour avec l'état actuel de votre application.

---

## Exemple : Injecter le profil utilisateur

```php
namespace App\Synapse\Context;

use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;

class UserContextProvider implements ContextProviderInterface
{
    public function __construct(private Security $security) {}

    public function getSystemPrompt(): string
    {
        $user = $this->security->getUser();
        if ($user) {
            return sprintf("Tu discutes avec %s. Parle-lui de manière amicale.", $user->getUserIdentifier());
        }
        return "Tu es un assistant IA utile.";
    }

    public function getInitialContext(): array
    {
        return [
            'language'     => 'fr',
            'current_time' => date('Y-m-d H:i:s'),
            'app_version'  => '2.1.0',
        ];
    }
}
```

---

## Enregistrement

Le service est résolu automatiquement via l'autoconfiguration Symfony. Si vous avez plusieurs providers, leurs instructions système sont concaténées dans l'ordre de priorité des services.

```yaml
# config/services.yaml
services:
    App\Synapse\Context\UserContextProvider:
        tags: ['synapse.context_provider']
```

---

## Voir aussi

- [Phases du PromptPipeline](../../explanation/architecture.md) — phase BUILD où ce provider est appelé (`ContextBuilderSubscriber`)
- [Configuration](../guides/configuration.md) — paramètres globaux du système
