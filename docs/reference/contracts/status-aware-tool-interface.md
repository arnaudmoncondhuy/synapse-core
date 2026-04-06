# StatusAwareToolInterface

Interface optionnelle pour les outils IA qui souhaitent afficher un message personnalisé dans l'indicateur de chargement pendant leur exécution.

## Namespace

```
ArnaudMoncondhuy\SynapseCore\Contract\StatusAwareToolInterface
```

## Contrat complet

```php
interface StatusAwareToolInterface
{
    public function getExecutingMessage(): string;
}
```

## Méthode

| Méthode | Rôle |
|---------|------|
| `getExecutingMessage(): string` | Message à afficher dans l'interface pendant l'exécution de l'outil. |

---

## Comportement par défaut

Si un outil n'implémente pas cette interface, le message affiché est :

```
Exécution de l'outil: {nom_de_l_outil}...
```

---

## Exemple

```php
namespace App\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\StatusAwareToolInterface;

class SearchTool implements AiToolInterface, StatusAwareToolInterface
{
    public function getName(): string
    {
        return 'search_database';
    }

    public function getDescription(): string
    {
        return 'Recherche dans la base de données produits.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Terme de recherche'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $parameters): mixed
    {
        // ... logique de recherche
        return ['results' => []];
    }

    // StatusAwareToolInterface
    public function getExecutingMessage(): string
    {
        return 'Recherche dans la base de données...';
    }
}
```

---

## Voir aussi

- [AiToolInterface](./ai-tool-interface.md) — interface principale à implémenter
- [Créer des outils IA](../../guides/ai-tools.md) — guide complet
