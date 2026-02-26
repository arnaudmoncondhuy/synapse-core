# Créer des outils IA (Function Calling)

Les outils permettent au LLM d'appeler des fonctions de votre application pour récupérer des données en temps réel ou effectuer des actions.

## 1. Implémenter l'interface

Créez une classe qui implémente `ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface`.

### Exemple : Outil de calcul de prix

```php
namespace App\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;

class PriceCalculatorTool implements AiToolInterface
{
    public function getName(): string
    {
        return 'calculate_total';
    }

    public function getDescription(): string
    {
        return 'Calcule le prix TTC à partir du HT. Utiliser cet outil quand l\'utilisateur demande un prix final.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ht_price' => ['type' => 'number', 'description' => 'Prix hors taxes'],
                'tva_rate' => ['type' => 'number', 'description' => 'Taux de TVA (ex: 20)'],
            ],
            'required' => ['ht_price', 'tva_rate'],
        ];
    }

    public function execute(array $parameters): mixed
    {
        return $parameters['ht_price'] * (1 + $parameters['tva_rate'] / 100);
    }
}
```

## 2. Enregistrement des outils

Le bundle utilise l'auto-configuration. Vous devez simplement taguer vos services avec `synapse.tool` (souvent automatique via `_instanceof` dans `services.yaml`).

```yaml
# config/services.yaml
services:
    _instanceof:
        ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface:
            tags: ['synapse.tool']
```

## 3. Utilisation

Une fois l'outil enregistré, le LLM décidera **automatiquement** de l'appeler si la question de l'utilisateur correspond à la description de l'outil.

> [!TIP]
> Plus votre `description` est précise, plus le LLM sera efficace pour choisir le bon outil au bon moment.
