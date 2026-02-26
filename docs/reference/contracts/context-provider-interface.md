# ContextProviderInterface

L'interface `ContextProviderInterface` est l'un des outils les plus puissants de Synapse Core. Elle permet d'injecter dynamiquement des instructions système et des données métiers au tout début de chaque échange avec l'IA.

## 🛠 Pourquoi l'utiliser ?

*   **Prompt Engineering Dynamique** : Injecter le nom de l'utilisateur, ses préférences ou son historique d'achats dans le prompt système.
*   **Multilinguisme** : Adapter la langue des instructions système selon la session de l'utilisateur.
*   **Isolation des données** : Donner à l'IA uniquement les informations dont elle a besoin pour le cas d'usage actuel.

---

## 📋 Résumé du Contrat

| Méthode | Entrée | Sortie | Rôle |
| :--- | :--- | :--- | :--- |
| `getInstructions()` | - | `string` | Retourne le texte qui sera ajouté au prompt système. |
| `getContextData()` | - | `array` | Retourne des données structurées (JSON) que l'IA peut exploiter. |

---

## 🚀 Exemple : Injecter le profil utilisateur

=== "UserContextProvider.php"

    ```php
    namespace App\Synapse\Context;

    use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
    use Symfony\Bundle\SecurityBundle\Security;

    class UserContextProvider implements ContextProviderInterface
    {
        public function __construct(private Security $security) {}

        public function getInstructions(): string
        {
            $user = $this->security->getUser();
            return sprintf("Tu discutes avec %s. Parle-lui de manière amicale.", $user->getUserIdentifier());
        }

        public function getContextData(): array
        {
            return [
                'language' => 'fr',
                'current_time' => date('Y-m-d H:i:s')
            ];
        }
    }
    ```

---

## 💡 Conseils d'implémentation

> [!TIP]
> **Chaînage** : Synapse Core permet de configurer plusieurs providers. Les instructions de chacun seront concaténées automatiquement pour former le prompt final.

*   **Fraîcheur des données** : Puisque cette méthode est appelée au moment de la génération, les données injectées sont toujours à jour avec l'état actuel de votre application.

---


