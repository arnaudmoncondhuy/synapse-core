# ConfigProviderInterface

L'interface `ConfigProviderInterface` permet d'ajuster dynamiquement les paramètres techniques de l'IA (température, filtres de sécurité) en fonction du contexte de votre application.

## 🛠 Pourquoi l'utiliser ?

*   **Adaptabilité** : Utiliser une température basse (précision) pour l'analyse de données et une température haute (créativité) pour la rédaction de mails.
*   **Sécurité à géométrie variable** : Activer des filtres de sécurité plus stricts selon le profil de l'utilisateur ou le salon de discussion.
*   **A/B Testing** : Comparer différents réglages de modèles sans modifier le code source.

---

## 📋 Résumé du Contrat

| Méthode | Rôle |
| :--- | :--- |
| `getConfig()` | Retourne un tableau de paramètres techniques (ex: `temperature`, `top_p`). |

---

## 🚀 Exemple : Configuration basée sur le rôle utilisateur

=== "RoleConfigProvider.php"

    ```php
    namespace App\Synapse\Config;

    use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
    use Symfony\Bundle\SecurityBundle\Security;

    class RoleConfigProvider implements ConfigProviderInterface
    {
        public function __construct(private Security $security) {}

        public function getConfig(): array
        {
            if ($this->security->isGranted('ROLE_CREATIVE')) {
                return ['temperature' => 1.2];
            }

            return ['temperature' => 0.2, 'top_p' => 0.1];
        }
    }
    ```

---

## 💡 Conseils d'implémentation

*   **Fusion des options** : Synapse Core fusionne intelligemment la configuration par défaut avec celle retournée par votre provider. Vous ne devez renvoyer que les clés que vous souhaitez surcharger.
*   **Limites** : Attention à ne pas renvoyer de valeurs hors limites (ex: température > 2.0 pour certains modèles), car cela pourrait provoquer des erreurs de l'API LLM.

---


